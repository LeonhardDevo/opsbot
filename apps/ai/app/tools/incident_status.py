from __future__ import annotations

import json
from typing import Any, ClassVar

from app.tools.base import Tool, ToolContext, ToolError, ToolResult

INCIDENT_PATH = "/internal/v1/opsbot/incidents/{number}"


class IncidentStatusTool(Tool):
    name = "incident_status"
    description = "Look up the current status and the responsible person of an incident by its number."
    parameters: ClassVar[dict[str, Any]] = {
        "type": "object",
        "properties": {"number": {"type": "integer", "minimum": 1}},
        "required": ["number"],
        "additionalProperties": False,
    }
    required_permission = "incidents.view_own"
    data_tool = True

    def run(self, ctx: ToolContext, args: dict[str, Any]) -> ToolResult:
        number = args.get("number")
        if isinstance(number, str) and number.isdigit():
            number = int(number)
        if not isinstance(number, int) or isinstance(number, bool) or number < 1:
            raise ToolError("number must be a positive integer")
        if ctx.user.id is None:
            raise ToolError("the user is unknown")
        response = ctx.web.get_optional_json(
            INCIDENT_PATH.format(number=number), {"workspace_id": ctx.workspace_id, "user_id": ctx.user.id}
        )
        if isinstance(response, dict):
            data: dict[str, Any] = {**response, "number": number, "found": True}
            summary = f"incident {response.get('display_number')}: {response.get('status')}"
        else:
            data = {"number": number, "found": False}
            summary = f"incident {number}: not found"
        return ToolResult(
            content=json.dumps({"summary": summary, **data}, ensure_ascii=False), has_data=True, data=data
        )
