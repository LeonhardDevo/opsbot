from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Literal, Protocol

ChatRole = Literal["system", "user", "assistant", "tool"]


@dataclass(frozen=True, slots=True)
class ToolCall:
    id: str
    name: str
    arguments: str


@dataclass(slots=True)
class ChatMessage:
    role: ChatRole
    content: str
    tool_calls: list[ToolCall] = field(default_factory=list)
    tool_call_id: str | None = None
    provider_blocks: list[dict[str, Any]] | None = None
    provider_id: str | None = None


@dataclass(frozen=True, slots=True)
class ToolDefinition:
    name: str
    description: str
    parameters: dict[str, Any]


@dataclass(frozen=True, slots=True)
class ChatUsage:
    prompt_tokens: int = 0
    completion_tokens: int = 0
    cached_tokens: int = 0


@dataclass(slots=True)
class JsonSchemaSpec:
    name: str
    schema: dict[str, Any]


@dataclass(slots=True)
class ChatRequest:
    model: str
    messages: list[ChatMessage]
    tools: list[ToolDefinition] = field(default_factory=list)
    temperature: float | None = None
    max_tokens: int | None = None
    json_schema: JsonSchemaSpec | None = None
    session_id: str | None = None
    metadata: dict[str, Any] = field(default_factory=dict)


@dataclass(slots=True)
class ChatResult:
    content: str | None
    tool_calls: list[ToolCall]
    usage: ChatUsage
    finish_reason: str
    served_model: str | None = None
    provider_blocks: list[dict[str, Any]] | None = None


class ProviderError(Exception):
    def __init__(self, message: str, *, status: int | None = None, retry_after: float | None = None) -> None:
        super().__init__(message)
        self.status = status
        self.retry_after = retry_after


class ProviderNotConfiguredError(ProviderError):
    def __init__(self, provider_id: str) -> None:
        super().__init__(
            f'AI provider "{provider_id}" is not configured (missing API key). '
            "Set it in .env or in the AI admin settings.",
            status=None,
        )
        self.provider_id = provider_id


class AiProvider(Protocol):
    @property
    def id(self) -> str: ...

    def chat(self, req: ChatRequest) -> ChatResult: ...
