from __future__ import annotations

import json
from typing import Any, ClassVar

from app.agent.labels import ROUTING_CATEGORIES
from app.tools.base import Tool, ToolContext, ToolError, ToolResult

ROUTING_PATH = "/internal/v1/opsbot/routing/resolve"


class WhoHandlesTool(Tool):
    name = "who_handles"
    description = (
        "Find out who in the company is responsible for a kind of problem (damage, complaint, no access, injury, "
        "lost item, other incident, question or task), according to the company's routing rules."
    )
    parameters: ClassVar[dict[str, Any]] = {
        "type": "object",
        "properties": {
            "category": {"type": "string", "enum": list(ROUTING_CATEGORIES)},
            "department_id": {"type": "integer", "minimum": 1},
        },
        "additionalProperties": False,
    }
    required_permission = "assistant.use"
    data_tool = True

    def run(self, ctx: ToolContext, args: dict[str, Any]) -> ToolResult:
        category = str(args.get("category") or "task")
        if category not in ROUTING_CATEGORIES:
            raise ToolError(f"unknown category '{category}'")
        department_id = args.get("department_id")
        if department_id is not None and (
            not isinstance(department_id, int) or isinstance(department_id, bool) or department_id < 1
        ):
            raise ToolError("department_id must be a positive integer")
        params: dict[str, Any] = {"workspace_id": ctx.workspace_id, "category": category}
        if department_id is not None:
            params["department_id"] = department_id
        if ctx.user.id is not None:
            params["user_id"] = ctx.user.id
        response = ctx.web.get_json(ROUTING_PATH, params)
        payload = response if isinstance(response, dict) else {}
        user = payload.get("user") if isinstance(payload.get("user"), dict) else None
        department = payload.get("department") if isinstance(payload.get("department"), dict) else None
        data: dict[str, Any] = {
            "category": category,
            "user": user,
            "department": department,
            "source": payload.get("source") or "rule",
        }
        summary = f"{category}: handled by {user.get('name')}" if user and user.get("name") else f"{category}: nobody"
        return ToolResult(
            content=json.dumps({"summary": summary, **data}, ensure_ascii=False), has_data=True, data=data
        )
