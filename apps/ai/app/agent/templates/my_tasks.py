from __future__ import annotations

from typing import Any

from app.agent.labels import TASK_STATUSES, count_phrase, label
from app.agent.templates.common import md_escape

MAX_LINES = 10


def _line(task: dict[str, Any], locale: str) -> str:
    number = md_escape(task.get("display_number") or f"#{task.get('id')}")
    title = md_escape(task.get("title") or "")
    parts = [f"- **{number}** {title}"]
    due = task.get("due_local")
    if due:
        parts.append(f"— {'срок' if locale == 'ru' else 'due'} {md_escape(due)}")
    status = str(task.get("status") or "")
    if task.get("overdue"):
        parts.append("(просрочена)" if locale == "ru" else "(overdue)")
    elif status == "in_progress":
        parts.append(f"({label(TASK_STATUSES, status, locale)})")
    return " ".join(parts)


def render(data: dict[str, Any], locale: str) -> str:
    tasks = [t for t in data.get("tasks") or [] if isinstance(t, dict)]
    overdue = data.get("status") == "overdue"
    intro = "По вашему списку задач" if locale == "ru" else "From your task list"
    if not tasks:
        if locale == "ru":
            return f"{intro}: просроченных задач нет." if overdue else f"{intro}: открытых задач нет."
        return f"{intro}: you have no overdue tasks." if overdue else f"{intro}: you have no open tasks."
    if locale == "ru":
        forms = (
            ("просроченная задача", "просроченные задачи", "просроченных задач")
            if overdue
            else (
                "открытая задача",
                "открытые задачи",
                "открытых задач",
            )
        )
        head = f"{intro}: у вас {count_phrase(len(tasks), 'ru', forms, ('', ''))}."
    else:
        forms_en = ("overdue task", "overdue tasks") if overdue else ("open task", "open tasks")
        head = f"{intro}: you have {count_phrase(len(tasks), 'en', ('', '', ''), forms_en)}."
    lines = [_line(task, locale) for task in tasks[:MAX_LINES]]
    return head + "\n\n" + "\n".join(lines)
