from __future__ import annotations

from datetime import date
from typing import Any

from fastapi import APIRouter, Depends

from app.agent.pipeline import ChatContext, ChatInput
from app.api.schemas import ChatIn, SearchIn
from app.deps import Container, get_container
from app.retrieval.filters import SearchFilters, Viewer
from app.security import require_internal_signature
from app.tools.base import ChatUser

router = APIRouter(prefix="/v1", dependencies=[Depends(require_internal_signature)], tags=["chat"])


def chat_input(body: ChatIn) -> ChatInput:
    return ChatInput(
        workspace_id=body.workspace_id,
        user=ChatUser(
            id=body.user.id,
            role=body.user.role,
            locale=body.user.locale,
            permissions=frozenset(body.user.permissions),
            department_ids=tuple(sorted(set(body.user.department_ids))),
            privileged=body.user.privileged,
        ),
        channel=body.channel,
        message=body.message,
        conversation_id=body.conversation_id,
        context=ChatContext(
            today=body.context.today,
            timezone=body.context.timezone,
            min_confidence=body.context.min_confidence,
            ai_external_allowed=body.context.ai_external_allowed,
        ),
    )


@router.post("/chat")
def chat(body: ChatIn, container: Container = Depends(get_container)) -> dict[str, Any]:
    return container.chat.answer(chat_input(body)).to_response()


@router.post("/search")
def search(body: SearchIn, container: Container = Depends(get_container)) -> dict[str, Any]:
    user = body.user
    viewer = Viewer(
        role=user.role if user else "owner",
        department_ids=tuple(user.department_ids) if user else (),
        privileged=user.privileged if user else True,
        today=body.today or date.today(),
        include_drafts=body.include_drafts,
    )
    filters = body.filters
    embedder = container.embeddings.active(
        wait=container.settings.embeddings_load_wait_seconds, external_allowed=body.ai_external_allowed
    )
    result = container.retriever.search(
        workspace_id=body.workspace_id,
        query=body.query,
        embedder=embedder,
        viewer=viewer,
        k=body.k,
        filters=SearchFilters(
            department_ids=tuple(filters.department_ids) if filters else (),
            tags=tuple(filters.tags) if filters else (),
            document_ids=tuple(filters.document_ids) if filters else (),
        ),
        resolve=False,
    )
    return {
        "query": body.query,
        "embedding_model": result.embedding_model,
        "confidence": round(result.confidence, 4),
        "min_confidence": container.settings.rag_min_confidence,
        "latency_ms": result.latency_ms,
        "results": [hit.to_dict() for hit in result.hits],
    }
