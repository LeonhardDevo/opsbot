from __future__ import annotations

import threading
import time
import uuid
from collections.abc import AsyncIterator, Awaitable, Callable
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request, Response
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from app import __version__
from app.api import admin, chat, documents, health, privacy
from app.config import Settings, get_settings
from app.deps import Container, build_container
from app.observability import HTTP_LATENCY, HTTP_REQUESTS, configure_logging, get_logger
from app.security import REQUEST_ID_HEADER, request_id_var

log = get_logger(__name__)


def _warm_up(container: Container) -> None:
    try:
        status = container.embeddings.status()
        log.info("embeddings_ready", model=status.active, degraded=status.degraded)
    except Exception as exc:
        log.warning("embeddings_warmup_failed", error=str(exc))
    try:
        pruned = container.memory.prune()
        if pruned:
            log.info("conversation_memory_pruned", rows=pruned)
    except Exception as exc:
        log.warning("conversation_memory_prune_failed", error=str(exc))


def create_app(
    container: Container | None = None, settings: Settings | None = None, *, warm_up: bool = True
) -> FastAPI:
    settings = settings or (container.settings if container else get_settings())
    configure_logging(settings.log_level, settings.log_json)

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        owned = container is None
        app.state.container = container or build_container(settings)
        if warm_up:
            threading.Thread(target=_warm_up, args=(app.state.container,), daemon=True, name="ai-warmup").start()
        log.info("ai_service_started", port=settings.port, product=settings.product_name, version=__version__)
        try:
            yield
        finally:
            if owned:
                app.state.container.close()

    app = FastAPI(
        title=f"{settings.product_name} AI service",
        version=__version__,
        lifespan=lifespan,
        docs_url=None if settings.is_production else "/docs",
        redoc_url=None,
        openapi_url=None if settings.is_production else "/openapi.json",
    )
    if container is not None:
        app.state.container = container

    @app.middleware("http")
    async def request_context(request: Request, call_next: Callable[[Request], Awaitable[Response]]) -> Response:
        incoming = request.headers.get(REQUEST_ID_HEADER, "")
        request_id = incoming if 8 <= len(incoming) <= 64 and incoming.replace("-", "").isalnum() else str(uuid.uuid4())
        token = request_id_var.set(request_id)
        started = time.perf_counter()
        status_code = 500
        try:
            response = await call_next(request)
            status_code = response.status_code
            response.headers[REQUEST_ID_HEADER] = request_id
            return response
        finally:
            route = request.scope.get("route")
            path = getattr(route, "path", "unmatched")
            elapsed = time.perf_counter() - started
            HTTP_REQUESTS.labels(request.method, path, str(status_code)).inc()
            HTTP_LATENCY.labels(request.method, path).observe(elapsed)
            if path not in ("/healthz", "/readyz", "/metrics"):
                log.info("http_request", method=request.method, path=path, status=status_code, ms=int(elapsed * 1000))
            request_id_var.reset(token)

    @app.exception_handler(RequestValidationError)
    async def validation_error(_: Request, exc: RequestValidationError) -> JSONResponse:
        errors: dict[str, list[str]] = {}
        for err in exc.errors():
            field = ".".join(str(p) for p in err.get("loc", ()) if p != "body") or "body"
            errors.setdefault(field, []).append(str(err.get("msg", "invalid")))
        summary = "; ".join(f"{field}: {messages[0]}" for field, messages in errors.items())
        return JSONResponse(
            status_code=422, content={"detail": summary, "message": "validation failed", "errors": errors}
        )

    app.include_router(health.router)
    app.include_router(chat.router)
    app.include_router(documents.router)
    app.include_router(admin.router)
    app.include_router(privacy.router)
    return app


app = create_app()
