from __future__ import annotations

import hashlib
import re
import threading
from collections.abc import Sequence

from sqlalchemy import text
from sqlalchemy.orm import Session

from app.observability import get_logger

HNSW_MAX_DIMS = 2000
_SAFE_MODEL = re.compile(r"^[\w.:/@+\-]{1,200}$")

log = get_logger(__name__)
_known: set[str] = set()
_lock = threading.Lock()


class UnsafeModelIdError(ValueError):
    pass


def sql_model_literal(model_id: str) -> str:
    if not _SAFE_MODEL.match(model_id):
        raise UnsafeModelIdError(f"unsupported characters in embedding model id: {model_id!r}")
    return "'" + model_id.replace("'", "''") + "'"


def index_name(model_id: str, dims: int) -> str:
    digest = hashlib.sha1(model_id.encode()).hexdigest()[:12]
    return f"ix_chunks_hnsw_{digest}_{dims}"


def vector_literal(values: Sequence[float]) -> str:
    return "[" + ",".join(f"{v:.7g}" for v in values) + "]"


def ensure_hnsw_index(session: Session, model_id: str, dims: int) -> str | None:
    if dims > HNSW_MAX_DIMS:
        log.warning("hnsw_index_skipped", model=model_id, dims=dims, reason="dims above HNSW limit")
        return None
    name = index_name(model_id, dims)
    bind = session.get_bind()
    cache_key = f"{bind.engine.url.render_as_string(hide_password=True)}::{name}"
    with _lock:
        if cache_key in _known:
            return name
    session.execute(text("SELECT pg_advisory_xact_lock(hashtext(:name))"), {"name": name})
    session.execute(
        text(
            f"CREATE INDEX IF NOT EXISTS {name} ON rag.chunks "
            f"USING hnsw ((embedding::vector({int(dims)})) vector_cosine_ops) "
            f"WHERE embedding_model = {sql_model_literal(model_id)}"
        )
    )
    with _lock:
        _known.add(cache_key)
    log.info("hnsw_index_ready", index=name, model=model_id, dims=dims)
    return name


def forget_known_indexes() -> None:
    with _lock:
        _known.clear()
