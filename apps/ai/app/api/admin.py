from __future__ import annotations

import threading
import time
from datetime import UTC, date, datetime, timedelta
from decimal import Decimal
from typing import Any

from fastapi import APIRouter, Depends, HTTPException, Query, status
from fastapi.responses import JSONResponse
from sqlalchemy import Date, cast, func, select

from app.agent.prompts import normalize_locale
from app.api.schemas import AiSettingsIn, ProviderKeyIn
from app.deps import Container, get_container
from app.models import AiUsage
from app.observability import get_logger
from app.providers.catalog import CATALOG, KEYLESS_PROVIDERS, get_entry
from app.providers.types import ChatMessage, ChatRequest, ProviderError
from app.security import require_internal_signature
from app.settings_store import EMBEDDING_PROVIDERS, SettingsValidationError

router = APIRouter(prefix="/v1/admin/ai", dependencies=[Depends(require_internal_signature)], tags=["admin"])
log = get_logger(__name__)


def _provider_payload(container: Container, provider_id: str, locale: str) -> dict[str, Any]:
    entry = get_entry(provider_id)
    assert entry is not None
    info = container.keys.info(entry.id)
    payload: dict[str, Any] = {
        "id": entry.id,
        "label": entry.label,
        "configured": container.keys.is_configured(entry.id),
        "needs_key": entry.needs_key,
        "key_source": info.source,
        "key_hint": info.hint,
        "default_model": entry.default_model,
        "models": list(entry.models),
    }
    note = entry.localized_note(locale)
    if note:
        payload["note"] = note
    return payload


def _settings_payload(container: Container) -> dict[str, Any]:
    ai = container.store.ai_settings()
    data = ai.to_dict()
    data["embeddings_status"] = container.embeddings.peek()
    data["embeddings_providers"] = list(EMBEDDING_PROVIDERS)
    return data


def _warm_embeddings(container: Container) -> None:
    try:
        container.embeddings.active()
    except Exception as exc:
        log.warning("embeddings_warmup_failed", error=str(exc))


@router.get("/providers")
def list_providers(
    locale: str = Query(default="en", max_length=16), container: Container = Depends(get_container)
) -> list[dict[str, Any]]:
    loc = normalize_locale(locale)
    return [_provider_payload(container, entry.id, loc) for entry in CATALOG]


@router.get("/providers/{provider_id}/models")
def provider_models(
    provider_id: str, refresh: bool = False, container: Container = Depends(get_container)
) -> dict[str, Any]:
    entry = get_entry(provider_id)
    if entry is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "unknown provider")
    if refresh:
        container.live_models.forget(provider_id)
    try:
        models = container.live_models.models(provider_id)
    except ProviderError as exc:
        return {
            "provider": provider_id,
            "source": "catalog",
            "models": [{"id": m, "free": False, "context": None, "structured": True} for m in entry.models],
            "error": str(exc),
        }
    if not models:
        return {
            "provider": provider_id,
            "source": "catalog",
            "models": [{"id": m, "free": False, "context": None, "structured": True} for m in entry.models],
            "error": None,
        }
    return {"provider": provider_id, "source": "live", "models": [m.to_dict() for m in models], "error": None}


@router.get("/settings")
def get_settings(container: Container = Depends(get_container)) -> dict[str, Any]:
    return _settings_payload(container)


@router.put("/settings", response_model=None)
def put_settings(body: AiSettingsIn, container: Container = Depends(get_container)) -> dict[str, Any] | JSONResponse:
    before = container.store.ai_settings()
    patch = body.model_dump(exclude_unset=True)
    if "embeddings" in patch and patch["embeddings"] is not None:
        patch["embeddings"] = {k: v for k, v in patch["embeddings"].items() if v is not None}
    try:
        after = container.store.update_ai_settings(patch)
    except SettingsValidationError as exc:
        return JSONResponse(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            content={"detail": str(exc), "message": str(exc), "errors": {exc.field: [str(exc)]}},
        )
    reembed_required = before.embeddings != after.embeddings
    if reembed_required:
        threading.Thread(target=_warm_embeddings, args=(container,), daemon=True, name="ai-embeddings").start()
    payload = _settings_payload(container)
    payload["reembed_required"] = reembed_required
    return payload


@router.put("/keys/{provider_id}")
def put_key(provider_id: str, body: ProviderKeyIn, container: Container = Depends(get_container)) -> dict[str, Any]:
    entry = get_entry(provider_id)
    if entry is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "unknown provider")
    if provider_id in KEYLESS_PROVIDERS:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_CONTENT, "this provider does not use an API key")
    info = container.keys.set_key(provider_id, body.api_key)
    container.live_models.forget(provider_id)
    return {
        "provider": provider_id,
        "configured": container.keys.is_configured(provider_id),
        "key_source": info.source,
        "key_hint": info.hint,
    }


@router.post("/test")
def test_provider(container: Container = Depends(get_container)) -> dict[str, Any]:
    ai = container.store.ai_settings()
    started = time.perf_counter()
    request = ChatRequest(
        model="",
        messages=[
            ChatMessage(role="system", content="You are a connectivity check. Reply with the single word OK."),
            ChatMessage(role="user", content="ping"),
        ],
        max_tokens=64,
        metadata={"ping": True},
    )
    try:
        managed = container.manager.chat(request, purpose="test")
    except ProviderError as exc:
        return {
            "ok": False,
            "latency_ms": int((time.perf_counter() - started) * 1000),
            "provider": ai.provider,
            "model": ai.model,
            "error": str(exc),
        }
    return {
        "ok": True,
        "latency_ms": managed.latency_ms,
        "provider": managed.provider,
        "model": managed.model,
        "reply": (managed.content or "").strip()[:200],
        "error": None,
    }


@router.get("/usage")
def usage(days: int = Query(default=30, ge=1, le=366), container: Container = Depends(get_container)) -> dict[str, Any]:
    today = datetime.now(UTC).date()
    start = today - timedelta(days=days - 1)
    since = datetime.combine(start, datetime.min.time(), tzinfo=UTC)
    day_col = cast(func.timezone("UTC", AiUsage.created_at), Date).label("day")
    with container.session_factory() as session:
        per_day = session.execute(
            select(
                day_col,
                func.coalesce(func.sum(AiUsage.prompt_tokens), 0),
                func.coalesce(func.sum(AiUsage.completion_tokens), 0),
                func.coalesce(func.sum(AiUsage.cost_usd), 0),
                func.count(),
            )
            .where(AiUsage.created_at >= since)
            .group_by(day_col)
        ).all()
        by_model = session.execute(
            select(
                AiUsage.provider,
                AiUsage.model,
                func.count(),
                func.coalesce(func.sum(AiUsage.cost_usd), 0),
                func.coalesce(func.sum(AiUsage.prompt_tokens), 0),
                func.coalesce(func.sum(AiUsage.completion_tokens), 0),
            )
            .where(AiUsage.created_at >= since)
            .group_by(AiUsage.provider, AiUsage.model)
            .order_by(func.count().desc())
        ).all()
        by_purpose = session.execute(
            select(
                AiUsage.purpose,
                func.count(),
                func.coalesce(func.sum(AiUsage.cost_usd), 0),
                func.coalesce(func.sum(AiUsage.prompt_tokens + AiUsage.completion_tokens), 0),
            )
            .where(AiUsage.created_at >= since)
            .group_by(AiUsage.purpose)
            .order_by(func.count().desc())
        ).all()
        errors = session.scalar(
            select(func.count()).select_from(AiUsage).where(AiUsage.created_at >= since, AiUsage.ok.is_(False))
        )

    by_day: dict[date, tuple[int, int, Decimal, int]] = {
        row[0]: (int(row[1]), int(row[2]), Decimal(row[3]), int(row[4])) for row in per_day
    }
    series = []
    for offset in range(days):
        day = start + timedelta(days=offset)
        prompt, completion, cost, requests = by_day.get(day, (0, 0, Decimal(0), 0))
        series.append(
            {
                "date": day.isoformat(),
                "prompt_tokens": prompt,
                "completion_tokens": completion,
                "cost_usd": float(cost),
                "requests": requests,
            }
        )
    total_cost = sum((Decimal(row[3]) for row in per_day), Decimal(0))
    return {
        "days": series,
        "by_model": [
            {
                "provider": r[0],
                "model": r[1],
                "requests": int(r[2]),
                "cost_usd": float(r[3]),
                "prompt_tokens": int(r[4]),
                "completion_tokens": int(r[5]),
            }
            for r in by_model
        ],
        "by_purpose": [
            {"purpose": r[0], "requests": int(r[1]), "cost_usd": float(r[2]), "tokens": int(r[3])} for r in by_purpose
        ],
        "total_cost_usd": float(total_cost),
        "total_requests": sum(int(r[4]) for r in per_day),
        "errors": int(errors or 0),
    }
