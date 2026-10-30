from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any, ClassVar

from app.agent.sources import Source, SourcePool
from app.clients.web import WebClient
from app.embeddings.base import Embedder
from app.providers.types import ToolDefinition
from app.retrieval.filters import Viewer
from app.retrieval.search import HybridRetriever


@dataclass(frozen=True, slots=True)
class ChatUser:
    id: int | None
    role: str
    locale: str
    permissions: frozenset[str]
    department_ids: tuple[int, ...] = ()
    privileged: bool = False

    def can(self, permission: str | None) -> bool:
        return permission is None or permission in self.permissions or "*" in self.permissions


@dataclass(slots=True)
class ToolContext:
    workspace_id: int
    user: ChatUser
    viewer: Viewer
    retriever: HybridRetriever
    embedder: Embedder
    web: WebClient
    sources: SourcePool
    top_k: int = 6
    public_web_url: str = ""


@dataclass(slots=True)
class ToolResult:
    content: str
    sources: list[Source] = field(default_factory=list)
    has_data: bool = False
    actions: list[dict[str, Any]] = field(default_factory=list)
    confidence: float | None = None
    data: dict[str, Any] | None = None


class ToolError(RuntimeError):
    pass


class Tool(ABC):
    name: str
    description: str
    parameters: ClassVar[dict[str, Any]]
    required_permission: str | None = None
    data_tool: bool = True

    def definition(self) -> ToolDefinition:
        return ToolDefinition(name=self.name, description=self.description, parameters=self.parameters)

    @abstractmethod
    def run(self, ctx: ToolContext, args: dict[str, Any]) -> ToolResult: ...


class ToolRegistry:
    def __init__(self) -> None:
        self._tools: dict[str, Tool] = {}

    def register(self, tool: Tool) -> None:
        if tool.name in self._tools:
            raise ValueError(f"tool '{tool.name}' is already registered")
        self._tools[tool.name] = tool

    def get(self, name: str) -> Tool | None:
        return self._tools.get(name)

    def all(self) -> list[Tool]:
        return list(self._tools.values())

    def available_for(self, user: ChatUser) -> list[Tool]:
        return [tool for tool in self._tools.values() if user.can(tool.required_permission)]
