from __future__ import annotations

import json
from dataclasses import dataclass, field
from typing import Any

from app.observability import get_logger
from app.providers.manager import AiManager
from app.providers.types import AiProvider, ChatMessage, ChatRequest, ToolCall
from app.tools.base import Tool, ToolContext, ToolError, ToolResult

MAX_TOOL_RESULT_CHARS = 12_000

log = get_logger(__name__)

_BUDGET_EXHAUSTED = {
    "en": "Tool budget exhausted. Answer now using only the sources and tool results above.",
    "ru": "Лимит вызовов инструментов исчерпан. Ответь сейчас, используя только источники и результаты выше.",
}


@dataclass(slots=True)
class LoopOutcome:
    text: str | None
    finish_reason: str
    provider: str
    model: str
    iterations: int
    tools_used: list[str] = field(default_factory=list)
    tool_data: bool = False
    tool_confidence: float = 0.0
    actions: list[dict[str, Any]] = field(default_factory=list)


class AgentLoop:
    def __init__(self, manager: AiManager, max_iterations: int = 4) -> None:
        self._manager = manager
        self._max_iterations = max_iterations

    def run(
        self,
        messages: list[ChatMessage],
        tools: list[Tool],
        ctx: ToolContext,
        *,
        metadata: dict[str, Any],
        provider: AiProvider | None = None,
    ) -> LoopOutcome:
        definitions = [tool.definition() for tool in tools]
        by_name = {tool.name: tool for tool in tools}
        outcome = LoopOutcome(text=None, finish_reason="stop", provider="", model="", iterations=0)
        seen_calls: dict[str, str] = {}
        locale = str(metadata.get("locale", "en"))

        while True:
            budget_left = outcome.iterations < self._max_iterations
            managed = self._manager.chat(
                ChatRequest(model="", messages=messages, tools=definitions, metadata=metadata),
                purpose="chat",
                workspace_id=ctx.workspace_id,
                provider=provider,
            )
            result = managed.result
            outcome.provider, outcome.model = managed.provider, managed.model
            outcome.text, outcome.finish_reason = result.content, result.finish_reason
            if not result.tool_calls or not budget_left:
                return outcome

            outcome.iterations += 1
            messages.append(
                ChatMessage(
                    role="assistant",
                    content=result.content or "",
                    tool_calls=list(result.tool_calls),
                    provider_blocks=result.provider_blocks,
                    provider_id=managed.provider,
                )
            )
            for call in result.tool_calls:
                content = self._execute(call, by_name, ctx, outcome, seen_calls)
                messages.append(ChatMessage(role="tool", content=content, tool_call_id=call.id))
            if outcome.iterations >= self._max_iterations:
                messages.append(
                    ChatMessage(role="user", content=_BUDGET_EXHAUSTED.get(locale, _BUDGET_EXHAUSTED["en"]))
                )

    def _execute(
        self,
        call: ToolCall,
        by_name: dict[str, Tool],
        ctx: ToolContext,
        outcome: LoopOutcome,
        seen_calls: dict[str, str],
    ) -> str:
        signature = f"{call.name}:{call.arguments}"
        if signature in seen_calls:
            return "Duplicate call: the result is the same as before.\n" + seen_calls[signature]
        tool = by_name.get(call.name)
        if tool is None:
            return f"Error: unknown tool '{call.name}'."
        if not ctx.user.can(tool.required_permission):
            return f"Error: the user is not allowed to use '{call.name}'."
        try:
            args = json.loads(call.arguments or "{}")
            if not isinstance(args, dict):
                raise ValueError("arguments must be a JSON object")
        except (TypeError, ValueError) as exc:
            return f"Error: invalid arguments for '{call.name}': {exc}"
        try:
            result: ToolResult = tool.run(ctx, args)
        except ToolError as exc:
            return f"Error: {exc}"
        except Exception as exc:
            log.warning("tool_failed", tool=call.name, error=str(exc), exc_info=True)
            return f"Error: '{call.name}' failed; continue without it."
        if call.name not in outcome.tools_used:
            outcome.tools_used.append(call.name)
        outcome.tool_data = outcome.tool_data or result.has_data
        if result.confidence is not None:
            outcome.tool_confidence = max(outcome.tool_confidence, result.confidence)
        outcome.actions.extend(result.actions)
        content = result.content[:MAX_TOOL_RESULT_CHARS]
        seen_calls[signature] = content
        return content
