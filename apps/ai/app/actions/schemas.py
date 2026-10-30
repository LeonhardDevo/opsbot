from __future__ import annotations

from typing import Annotated, Any, Literal

from pydantic import BaseModel, ConfigDict, Field, ValidationError

IncidentCategory = Literal["damage", "complaint", "no_access", "injury", "lost_item", "other"]
Severity = Literal["low", "medium", "high"]
EscalateReason = Literal["low_confidence", "not_found", "conflict", "user_request", "limit_reached"]
ActionType = Literal["create_incident", "create_task", "escalate", "open_source", "ask"]

ACTION_ORDER: tuple[str, ...] = ("create_incident", "create_task", "escalate", "open_source", "ask")
MAX_ACTIONS = 4

DEFAULT_SEVERITY: dict[str, Severity] = {
    "injury": "high",
    "damage": "medium",
    "complaint": "medium",
    "lost_item": "medium",
    "no_access": "low",
    "other": "low",
}


class _Strict(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)


class CreateIncidentPayload(_Strict):
    category: IncidentCategory
    severity: Severity
    description: Annotated[str, Field(min_length=1, max_length=1000)]
    client_ref: Annotated[str, Field(max_length=120)] | None = None
    department_id: Annotated[int, Field(ge=1)] | None = None


class CreateTaskPayload(_Strict):
    title: Annotated[str, Field(min_length=3, max_length=160)]
    description: Annotated[str, Field(max_length=1000)] | None = None
    due_in_hours: Annotated[int, Field(ge=1, le=720)] | None = None


class EscalatePayload(_Strict):
    reason: EscalateReason


class OpenSourcePayload(_Strict):
    document_id: Annotated[int, Field(ge=1)]
    chunk_id: Annotated[int, Field(ge=1)] | None = None
    page: Annotated[int, Field(ge=1)] | None = None


class AskPayload(_Strict):
    question: Annotated[str, Field(min_length=1, max_length=200)]


PAYLOADS: dict[str, type[_Strict]] = {
    "create_incident": CreateIncidentPayload,
    "create_task": CreateTaskPayload,
    "escalate": EscalatePayload,
    "open_source": OpenSourcePayload,
    "ask": AskPayload,
}


class ProposedAction(_Strict):
    type: ActionType
    label: Annotated[str, Field(min_length=1, max_length=64)]
    payload: dict[str, Any]


def validate_action(raw: Any) -> dict[str, Any] | None:
    if not isinstance(raw, dict):
        return None
    try:
        action = ProposedAction.model_validate(raw)
        payload = PAYLOADS[action.type].model_validate(action.payload)
    except (ValidationError, KeyError):
        return None
    return {"type": action.type, "label": action.label, "payload": payload.model_dump(exclude_none=True)}
