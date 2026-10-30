from __future__ import annotations

import json
import re
from collections.abc import Callable
from typing import Any

import httpx2
import openai

from app.providers.types import ChatMessage, ChatRequest, ChatResult, ChatUsage, ProviderError, ToolCall

CLIENT_USER_AGENT = "platform-ai/1.0"
SESSION_HEADER = "x-opencode-session"
_STRUCTURED_UNSUPPORTED = re.compile(r"json_schema|response_format|structured|not support", re.IGNORECASE)
_REASONING_MODEL = re.compile(r"^(gpt-5|o[134])", re.IGNORECASE)


def parse_retry_after(headers: Any) -> float | None:
    if headers is None:
        return None
    try:
        ms = headers.get("retry-after-ms")
        if ms:
            return max(0.0, float(ms) / 1000.0)
        value = headers.get("retry-after")
        if value:
            return max(0.0, float(value))
    except (TypeError, ValueError):
        return None
    return None


def openai_error_to_provider_error(provider_id: str, exc: Exception) -> ProviderError:
    if isinstance(exc, openai.APIStatusError):
        return ProviderError(
            f"{provider_id}: HTTP {exc.status_code}: {exc.message}",
            status=exc.status_code,
            retry_after=parse_retry_after(exc.response.headers),
        )
    if isinstance(exc, openai.APIConnectionError):
        return ProviderError(f"{provider_id}: connection error: {exc}", status=None)
    return ProviderError(f"{provider_id}: {exc}", status=None)


def default_supports_temperature(model: str) -> bool:
    return True


def openai_supports_temperature(model: str) -> bool:
    return not _REASONING_MODEL.match(model)


class OpenAiCompatibleProvider:
    def __init__(
        self,
        provider_id: str,
        *,
        base_url: str,
        api_key: str,
        identify_client: bool = False,
        send_session_header: bool = False,
        max_tokens_param: str = "max_tokens",
        supports_temperature: Callable[[str], bool] = default_supports_temperature,
        timeout: float = 90.0,
        http_client: httpx2.Client | None = None,
    ) -> None:
        self._id = provider_id
        self._client = openai.OpenAI(
            base_url=base_url,
            api_key=api_key or "none",
            max_retries=0,
            timeout=timeout,
            default_headers={"User-Agent": CLIENT_USER_AGENT} if identify_client else None,
            http_client=http_client,
        )
        self._send_session_header = send_session_header
        self._max_tokens_param = max_tokens_param
        self._supports_temperature = supports_temperature

    @property
    def id(self) -> str:
        return self._id

    def chat(self, req: ChatRequest) -> ChatResult:
        source = req.messages
        if req.json_schema is not None:
            instruction = (
                "\n\nRespond ONLY with a single JSON object matching this JSON Schema, no markdown, no prose:\n"
                + json.dumps(req.json_schema.schema, ensure_ascii=False)
            )
            source = [
                ChatMessage(role=m.role, content=m.content + instruction) if i == 0 and m.role == "system" else m
                for i, m in enumerate(req.messages)
            ]
        base: dict[str, Any] = {"model": req.model, "messages": [to_openai_message(m) for m in source]}
        if req.temperature is not None and self._supports_temperature(req.model):
            base["temperature"] = req.temperature
        if req.max_tokens:
            base[self._max_tokens_param] = req.max_tokens
        if req.tools:
            base["tools"] = [
                {
                    "type": "function",
                    "function": {"name": t.name, "description": t.description, "parameters": t.parameters},
                }
                for t in req.tools
            ]
        extra_headers = {SESSION_HEADER: req.session_id or "platform-default"} if self._send_session_header else None

        try:
            if req.json_schema is not None:
                try:
                    response = self._client.chat.completions.create(
                        **base,
                        response_format={
                            "type": "json_schema",
                            "json_schema": {
                                "name": req.json_schema.name,
                                "strict": True,
                                "schema": req.json_schema.schema,
                            },
                        },
                        extra_headers=extra_headers,
                    )
                except openai.APIStatusError as exc:
                    if exc.status_code != 400 and not _STRUCTURED_UNSUPPORTED.search(str(exc.message)):
                        raise
                    response = self._client.chat.completions.create(
                        **base, response_format={"type": "json_object"}, extra_headers=extra_headers
                    )
            else:
                response = self._client.chat.completions.create(**base, extra_headers=extra_headers)
        except (openai.APIStatusError, openai.APIConnectionError) as exc:
            raise openai_error_to_provider_error(self._id, exc) from exc

        if not response.choices:
            extra = response.model_extra or {}
            err = extra.get("error") or {}
            message = err.get("message") if isinstance(err, dict) else None
            raw_code = err.get("code") if isinstance(err, dict) else None
            code = int(raw_code) if isinstance(raw_code, int | str) and str(raw_code).isdigit() else None
            status = code if code is not None and (code == 429 or code >= 500) else 429
            raise ProviderError(f"{self._id}: {message or 'empty choices in response'}", status=status)

        choice = response.choices[0]
        tool_calls = [
            ToolCall(id=c.id, name=c.function.name, arguments=c.function.arguments or "{}")
            for c in (choice.message.tool_calls or [])
            if getattr(c, "type", "function") == "function" and getattr(c, "function", None) is not None
        ]
        usage = response.usage
        cached = 0
        if usage is not None:
            usage_extra = usage.model_extra or {}
            details = usage.prompt_tokens_details
            cached = int(usage_extra.get("prompt_cache_hit_tokens") or (details.cached_tokens if details else 0) or 0)
        return ChatResult(
            content=choice.message.content,
            tool_calls=tool_calls,
            usage=ChatUsage(
                prompt_tokens=usage.prompt_tokens if usage else 0,
                completion_tokens=usage.completion_tokens if usage else 0,
                cached_tokens=cached,
            ),
            finish_reason=choice.finish_reason or "stop",
            served_model=response.model,
        )


def to_openai_message(m: ChatMessage) -> dict[str, Any]:
    if m.role == "assistant" and m.tool_calls:
        return {
            "role": "assistant",
            "content": m.content or None,
            "tool_calls": [
                {"id": c.id, "type": "function", "function": {"name": c.name, "arguments": c.arguments}}
                for c in m.tool_calls
            ],
        }
    if m.role == "tool":
        return {"role": "tool", "content": m.content, "tool_call_id": m.tool_call_id or ""}
    return {"role": m.role, "content": m.content}
