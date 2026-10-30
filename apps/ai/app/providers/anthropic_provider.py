from __future__ import annotations

import json
from typing import Any

import anthropic
import httpx2

from app.providers.openai_compat import parse_retry_after
from app.providers.types import ChatMessage, ChatRequest, ChatResult, ChatUsage, ProviderError, ToolCall

SERVER_FALLBACK_BETA = "server-side-fallback-2026-07-01"
SERVER_FALLBACK_MODELS = frozenset({"claude-opus-5-5", "claude-opus-5", "claude-fable-5-1", "claude-sonnet-5-5"})
REPLAYABLE_BLOCK_TYPES = frozenset({"text", "thinking", "redacted_thinking", "tool_use"})
DEFAULT_MAX_TOKENS = 16000


def anthropic_error_to_provider_error(exc: Exception) -> ProviderError:
    if isinstance(exc, anthropic.APIStatusError):
        return ProviderError(
            f"anthropic: HTTP {exc.status_code}: {exc.message}",
            status=exc.status_code,
            retry_after=parse_retry_after(exc.response.headers),
        )
    if isinstance(exc, anthropic.APIConnectionError):
        return ProviderError(f"anthropic: connection error: {exc}", status=None)
    return ProviderError(f"anthropic: {exc}", status=None)


class AnthropicProvider:
    def __init__(
        self,
        api_key: str,
        *,
        timeout: float = 90.0,
        server_fallbacks: bool = True,
        http_client: httpx2.Client | None = None,
        base_url: str | None = None,
    ) -> None:
        self._client = anthropic.Anthropic(
            api_key=api_key, max_retries=0, timeout=timeout, http_client=http_client, base_url=base_url
        )
        self._server_fallbacks = server_fallbacks

    @property
    def id(self) -> str:
        return "anthropic"

    def chat(self, req: ChatRequest) -> ChatResult:
        system = "\n\n".join(m.content for m in req.messages if m.role == "system")
        if req.json_schema is not None:
            system += "\n\nRespond ONLY with a JSON object matching this JSON Schema, no prose:\n" + json.dumps(
                req.json_schema.schema, ensure_ascii=False
            )
        kwargs: dict[str, Any] = {
            "model": req.model,
            "max_tokens": req.max_tokens or DEFAULT_MAX_TOKENS,
            "messages": to_anthropic_messages([m for m in req.messages if m.role != "system"]),
        }
        if system.strip():
            kwargs["system"] = system
        if req.tools:
            kwargs["tools"] = [
                {"name": t.name, "description": t.description, "input_schema": t.parameters} for t in req.tools
            ]
        try:
            if self._server_fallbacks and req.model in SERVER_FALLBACK_MODELS:
                response: Any = self._client.beta.messages.create(
                    **kwargs, betas=[SERVER_FALLBACK_BETA], fallbacks="default"
                )
            else:
                response = self._client.messages.create(**kwargs)
        except (anthropic.APIStatusError, anthropic.APIConnectionError) as exc:
            raise anthropic_error_to_provider_error(exc) from exc

        usage = response.usage
        cache_read = int(getattr(usage, "cache_read_input_tokens", 0) or 0)
        cache_write = int(getattr(usage, "cache_creation_input_tokens", 0) or 0)
        result_usage = ChatUsage(
            prompt_tokens=int(usage.input_tokens or 0) + cache_read + cache_write,
            completion_tokens=int(usage.output_tokens or 0),
            cached_tokens=cache_read,
        )
        if response.stop_reason == "refusal":
            return ChatResult(
                content=None, tool_calls=[], usage=result_usage, finish_reason="refusal", served_model=response.model
            )

        text_parts: list[str] = []
        tool_calls: list[ToolCall] = []
        blocks: list[dict[str, Any]] = []
        for block in response.content:
            block_type = getattr(block, "type", None)
            if block_type == "text":
                text_parts.append(block.text)
            elif block_type == "tool_use":
                tool_calls.append(ToolCall(id=block.id, name=block.name, arguments=json.dumps(block.input)))
            if block_type in REPLAYABLE_BLOCK_TYPES:
                blocks.append(block.model_dump(mode="json", exclude_none=True))
        text = "".join(text_parts)
        return ChatResult(
            content=text or None,
            tool_calls=tool_calls,
            usage=result_usage,
            finish_reason=response.stop_reason or "end_turn",
            served_model=response.model,
            provider_blocks=blocks,
        )

    def list_models(self) -> list[str]:
        try:
            return [m.id for m in self._client.models.list(limit=100)]
        except (anthropic.APIStatusError, anthropic.APIConnectionError) as exc:
            raise anthropic_error_to_provider_error(exc) from exc


def to_anthropic_messages(messages: list[ChatMessage]) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    for m in messages:
        if m.role == "assistant" and m.provider_blocks and m.provider_id == "anthropic":
            out.append({"role": "assistant", "content": m.provider_blocks})
        elif m.role == "assistant" and m.tool_calls:
            content: list[dict[str, Any]] = []
            if m.content:
                content.append({"type": "text", "text": m.content})
            for call in m.tool_calls:
                content.append(
                    {"type": "tool_use", "id": call.id, "name": call.name, "input": safe_json_object(call.arguments)}
                )
            out.append({"role": "assistant", "content": content})
        elif m.role == "tool":
            block = {"type": "tool_result", "tool_use_id": m.tool_call_id or "", "content": m.content}
            prev = out[-1] if out else None
            if (
                prev is not None
                and prev["role"] == "user"
                and isinstance(prev["content"], list)
                and all(b.get("type") == "tool_result" for b in prev["content"])
            ):
                prev["content"].append(block)
            else:
                out.append({"role": "user", "content": [block]})
        else:
            out.append({"role": m.role, "content": m.content})
    return out


def safe_json_object(raw: str) -> dict[str, Any]:
    try:
        value = json.loads(raw)
    except (TypeError, ValueError):
        return {}
    return value if isinstance(value, dict) else {}
