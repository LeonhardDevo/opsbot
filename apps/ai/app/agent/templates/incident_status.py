from __future__ import annotations

from typing import Any

from app.agent.labels import INCIDENT_CATEGORY_TITLES, INCIDENT_STATUSES, label
from app.agent.templates.common import md_escape


def render(data: dict[str, Any], locale: str) -> str:
    intro = "По журналу инцидентов" if locale == "ru" else "From the incident log"
    if not data.get("found"):
        number = data.get("number")
        display = f"№ {number}" if locale == "ru" else f"#{number}"
        if locale == "ru":
            return f"{intro}: инцидент {display} не найден среди инцидентов, доступных вам."
        return f"{intro}: incident {display} was not found among the incidents you can see."
    display = md_escape(data.get("display_number") or data.get("number"))
    category = label(INCIDENT_CATEGORY_TITLES, str(data.get("category") or "other"), locale)
    status = label(INCIDENT_STATUSES, str(data.get("status") or "open"), locale)
    assignee = data.get("assignee_name")
    updated = data.get("updated_local")
    if locale == "ru":
        lines = [f"{intro}: инцидент **{display}** ({category}) — статус «{status}»."]
        lines.append(f"Ответственный: {md_escape(assignee)}." if assignee else "Ответственный пока не назначен.")
        if updated:
            lines.append(f"Последнее обновление: {md_escape(updated)}.")
        return " ".join(lines)
    lines = [f"{intro}: incident **{display}** ({category}) is {status}."]
    lines.append(f"Responsible: {md_escape(assignee)}." if assignee else "Nobody is assigned yet.")
    if updated:
        lines.append(f"Last update: {md_escape(updated)}.")
    return " ".join(lines)
