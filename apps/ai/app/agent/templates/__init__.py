from __future__ import annotations

from collections.abc import Callable
from typing import Any

from app.agent.templates import incident_status, my_tasks, who_handles

Renderer = Callable[[dict[str, Any], str], str]

RENDERERS: dict[str, Renderer] = {
    "who_handles": who_handles.render,
    "my_tasks": my_tasks.render,
    "incident_status": incident_status.render,
}


def render_tool_answer(tool: str, data: dict[str, Any], locale: str) -> str:
    renderer = RENDERERS.get(tool)
    if renderer is None:
        raise KeyError(f"no template for tool '{tool}'")
    return renderer(data, "ru" if locale == "ru" else "en")
