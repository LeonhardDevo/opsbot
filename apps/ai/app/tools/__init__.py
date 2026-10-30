from __future__ import annotations

from app.tools.base import ChatUser, Tool, ToolContext, ToolError, ToolRegistry, ToolResult
from app.tools.incident_status import IncidentStatusTool
from app.tools.intents import INTENT_RULES, IntentMatch, match_data_intent
from app.tools.my_tasks import MyTasksTool
from app.tools.search_documents import SearchDocumentsTool
from app.tools.who_handles import WhoHandlesTool

__all__ = [
    "INTENT_RULES",
    "ChatUser",
    "IncidentStatusTool",
    "IntentMatch",
    "MyTasksTool",
    "SearchDocumentsTool",
    "Tool",
    "ToolContext",
    "ToolError",
    "ToolRegistry",
    "ToolResult",
    "WhoHandlesTool",
    "build_registry",
    "match_data_intent",
]


def build_registry() -> ToolRegistry:
    registry = ToolRegistry()
    registry.register(SearchDocumentsTool())
    registry.register(WhoHandlesTool())
    registry.register(MyTasksTool())
    registry.register(IncidentStatusTool())
    return registry
