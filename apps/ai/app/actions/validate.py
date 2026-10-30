from __future__ import annotations

import re
from collections.abc import Iterable, Sequence
from typing import Any

from app.actions.schemas import ACTION_ORDER, DEFAULT_SEVERITY, MAX_ACTIONS, validate_action
from app.observability import ACTIONS_DROPPED
from app.tools.intents import incident_category, wants_task

LABELS: dict[str, dict[str, str]] = {
    "create_incident": {"en": "Create incident", "ru": "Создать инцидент"},
    "create_task": {"en": "Create task", "ru": "Создать задачу"},
    "escalate": {"en": "Pass to manager", "ru": "Передать менеджеру"},
    "open_source": {"en": "Open source", "ru": "Открыть источник"},
    "ask": {"en": "Ask again", "ru": "Спросить снова"},
}

REQUIRED_PERMISSION: dict[str, str] = {
    "create_incident": "incidents.create",
    "create_task": "tasks.create",
    "escalate": "assistant.use",
}

ESCALATE_REASONS: dict[str, str] = {
    "low_confidence": "low_confidence",
    "not_found": "not_found",
    "conflict": "conflict",
    "model_refusal": "not_found",
    "unavailable": "not_found",
}

DESCRIPTION_LIMIT = 1000
TITLE_LIMIT = 160
_TASK_PREFIX = re.compile(
    r"^\s*(?:пожалуйста[,\s]+)?(?:создай\s+задачу|поставь\s+задачу|напомни(?:\s+мне)?|please\s+)?"
    r"(?:create\s+a\s+task|add\s+a\s+task|remind\s+me)?\s*[:,\-—]?\s*(?:to\s+|чтобы\s+)?",
    re.IGNORECASE,
)


def label_for(action_type: str, locale: str) -> str:
    return LABELS[action_type]["ru" if locale == "ru" else "en"]


def _task_title(message: str) -> str:
    stripped = _TASK_PREFIX.sub("", message, count=1).strip(" .!?")
    title = stripped or message.strip()
    title = " ".join(title.split())
    if len(title) > TITLE_LIMIT:
        title = title[: TITLE_LIMIT - 1].rstrip() + "…"
    return title[:1].upper() + title[1:]


def _allowed(action_type: str, permissions: frozenset[str]) -> bool:
    needed = REQUIRED_PERMISSION.get(action_type)
    return needed is None or needed in permissions or "*" in permissions


def propose_actions(
    message: str,
    *,
    locale: str,
    permissions: Iterable[str],
    refused: bool,
    refusal_reason: str | None = None,
    citations: Sequence[dict[str, Any]] = (),
) -> list[dict[str, Any]]:
    granted = frozenset(permissions)
    text = message.strip()
    description = text[:DESCRIPTION_LIMIT]
    candidates: list[dict[str, Any]] = []
    category = incident_category(text)
    if category is not None:
        candidates.append(
            {
                "type": "create_incident",
                "label": label_for("create_incident", locale),
                "payload": {"category": category, "severity": DEFAULT_SEVERITY[category], "description": description},
            }
        )
    if wants_task(text):
        payload: dict[str, Any] = {"title": _task_title(text)}
        if description:
            payload["description"] = description
        candidates.append({"type": "create_task", "label": label_for("create_task", locale), "payload": payload})
    if refused:
        reason = ESCALATE_REASONS.get(refusal_reason or "not_found", "not_found")
        candidates.append({"type": "escalate", "label": label_for("escalate", locale), "payload": {"reason": reason}})
    elif citations:
        top = citations[0]
        source: dict[str, Any] = {"document_id": top.get("document_id")}
        if top.get("chunk_id"):
            source["chunk_id"] = top["chunk_id"]
        if top.get("page"):
            source["page"] = top["page"]
        candidates.append({"type": "open_source", "label": label_for("open_source", locale), "payload": source})
    return finalize_actions(candidates, granted, refused=refused, locale=locale)


def finalize_actions(
    candidates: Sequence[Any], permissions: frozenset[str], *, refused: bool, locale: str
) -> list[dict[str, Any]]:
    valid: list[dict[str, Any]] = []
    seen: set[str] = set()
    for raw in candidates:
        action = validate_action(raw)
        if action is None:
            ACTIONS_DROPPED.labels("invalid").inc()
            continue
        if not _allowed(action["type"], permissions):
            ACTIONS_DROPPED.labels("permission").inc()
            continue
        if action["type"] in seen:
            ACTIONS_DROPPED.labels("duplicate").inc()
            continue
        seen.add(action["type"])
        valid.append(action)
    if refused and "escalate" not in seen:
        valid.append({"type": "escalate", "label": label_for("escalate", locale), "payload": {"reason": "not_found"}})
    valid.sort(key=lambda a: ACTION_ORDER.index(a["type"]))
    if len(valid) > MAX_ACTIONS:
        ACTIONS_DROPPED.labels("limit").inc(len(valid) - MAX_ACTIONS)
        keep = valid[:MAX_ACTIONS]
        if refused and not any(a["type"] == "escalate" for a in keep):
            keep[-1] = next(a for a in valid if a["type"] == "escalate")
        valid = keep
    return valid
