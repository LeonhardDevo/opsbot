from __future__ import annotations

from typing import Any

from app.agent.labels import CATEGORY_LABELS, label
from app.agent.templates.common import md_escape

_INTRO = {
    "rule": {"en": "From the routing rules", "ru": "По правилам маршрутизации"},
    "department_manager": {"en": "From the department settings", "ru": "По настройкам отдела"},
    "workspace_owner": {"en": "From the routing rules", "ru": "По правилам маршрутизации"},
}


def render(data: dict[str, Any], locale: str) -> str:
    source = str(data.get("source") or "rule")
    intro = (_INTRO.get(source) or _INTRO["rule"])[locale]
    topic = label(CATEGORY_LABELS, str(data.get("category") or "task"), locale)
    user = data.get("user") if isinstance(data.get("user"), dict) else None
    department = data.get("department") if isinstance(data.get("department"), dict) else None
    if not user or not user.get("name"):
        if locale == "ru":
            return f"{intro}: ответственный за тему «{topic}» пока не назначен. Обратитесь к своему менеджеру."
        return f"{intro}: nobody is assigned to {topic} yet. Please contact your manager."
    name = md_escape(user["name"])
    dept = md_escape(department["name"]) if department and department.get("name") else None
    if locale == "ru":
        text = f"{intro}: тему «{topic}» ведёт **{name}**"
        if dept:
            text += f" (отдел «{dept}»)"
        if source == "workspace_owner":
            text += ". Отдельного правила нет, поэтому вопрос решает руководитель компании"
        return text + "."
    text = f"{intro}: **{name}** handles {topic}"
    if dept:
        text += f" (department: {dept})"
    if source == "workspace_owner":
        text += ". There is no specific rule, so the company owner handles it"
    return text + "."
