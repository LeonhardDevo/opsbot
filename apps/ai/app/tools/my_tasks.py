from __future__ import annotations

import json
from typing import Any, ClassVar

from app.tools.base import Tool, ToolContext, ToolError, ToolResult

TASKS_PATH = "/internal/v1/opsbot/tasks"
LIMIT = 10


class MyTasksTool(Tool):
    name = "my_tasks"
    description = "List the current user's own open or overdue tasks from the company task list."
    parameters: ClassVar[dict[str, Any]] = {
        "type": "object",
        "properties": {"status": {"type": "string", "enum": ["open", "overdue"]}},
        "additionalProperties": False,
    }
    required_permission = "tasks.view_own"
    data_tool = True

    def run(self, ctx: ToolContext, args: dict[str, Any]) -> ToolResult:
        status = str(args.get("status") or "open")
        if status not in ("open", "overdue"):
            raise ToolError("status must be 'open' or 'overdue'")
        if ctx.user.id is None:
            raise ToolError("the user is unknown")
        response = ctx.web.get_json(
            TASKS_PATH, {"workspace_id": ctx.workspace_id, "user_id": ctx.user.id, "status": status, "limit": LIMIT}
        )
        raw = response.get("tasks") if isinstance(response, dict) else None
        tasks = [task for task in raw or [] if isinstance(task, dict)][:LIMIT]
        data: dict[str, Any] = {"status": status, "tasks": tasks}
        names = ", ".join(f"{task.get('display_number')} {task.get('title')}" for task in tasks)
        summary = f"{len(tasks)} {status} task(s): {names}"
        return ToolResult(
            content=json.dumps({"summary": summary, **data}, ensure_ascii=False), has_data=True, data=data
        )
