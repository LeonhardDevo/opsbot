from __future__ import annotations

from typing import Any

from fastapi import APIRouter, Depends, Response
from fastapi.responses import JSONResponse
from prometheus_client import CONTENT_TYPE_LATEST, generate_latest

from app import __version__
from app.db import ping
from app.deps import Container, get_container
from app.observability import REGISTRY

router = APIRouter(tags=["health"])


def _identity(container: Container) -> dict[str, Any]:
    return {
        "service": container.settings.service_name,
        "product": container.settings.product_name,
        "product_slug": container.settings.product_slug,
        "version": __version__,
    }


@router.get("/healthz")
def healthz(container: Container = Depends(get_container)) -> dict[str, Any]:
    return {"status": "ok", **_identity(container)}


@router.get("/readyz", response_model=None)
def readyz(container: Container = Depends(get_container)) -> JSONResponse:
    db_ok = ping(container.engine)
    checks: dict[str, Any] = {"database": "ok" if db_ok else "unavailable"}
    if db_ok:
        try:
            checks["embeddings"] = container.embeddings.peek()
        except Exception as exc:
            checks["embeddings"] = {"state": "error", "reason": str(exc)}
    payload = {"status": "ok" if db_ok else "unavailable", **_identity(container), "checks": checks}
    return JSONResponse(payload, status_code=200 if db_ok else 503)


@router.get("/metrics")
def metrics() -> Response:
    return Response(generate_latest(REGISTRY), media_type=CONTENT_TYPE_LATEST)
