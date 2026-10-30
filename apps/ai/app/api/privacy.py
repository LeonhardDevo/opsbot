from __future__ import annotations

from typing import Any

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field

from app.deps import Container, get_container
from app.observability import get_logger
from app.privacy.purge import MemoryPruner, UserPurger
from app.security import require_internal_signature

router = APIRouter(prefix="/v1", dependencies=[Depends(require_internal_signature)], tags=["privacy"])
log = get_logger(__name__)


class WorkspaceRetentionIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    workspace_id: int = Field(gt=0)
    days: int = Field(ge=1, le=3650)


class MemoryPruneIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    default_days: int = Field(ge=1, le=3650)
    workspaces: list[WorkspaceRetentionIn] = Field(default_factory=list, max_length=100_000)


@router.delete("/users/{user_id}")
def purge_user(user_id: int, container: Container = Depends(get_container)) -> dict[str, Any]:
    if user_id <= 0:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "user not found")
    deleted = UserPurger(container.session_factory).purge(user_id)
    log.info("user_purged", user_id=user_id, conversation_turns=deleted)
    return {"user_id": user_id, "purged": {"conversation_turns": deleted}}


@router.post("/privacy/prune-memory")
def prune_memory(body: MemoryPruneIn, container: Container = Depends(get_container)) -> dict[str, Any]:
    pruned = MemoryPruner(container.session_factory).prune(
        body.default_days, {item.workspace_id: item.days for item in body.workspaces}
    )
    log.info("conversation_memory_pruned", rows=pruned)
    return {"pruned": pruned}
