from __future__ import annotations

import hashlib
import time
from dataclasses import dataclass, field
from typing import Any

from app.embeddings.registry import EmbeddingsRegistry
from app.ingest.chunker import Chunker
from app.ingest.pages import PageText, pages_to_blocks
from app.observability import INGEST_DURATION, INGEST_RESULTS, get_logger
from app.retrieval.store import ChunkStore, DocumentMeta, StaleRevisionError, embedding_text

log = get_logger(__name__)

PAGED_MIMES = frozenset({"application/pdf", "application/x-pdf"})


class IndexingError(RuntimeError):
    def __init__(self, code: str, message: str, status_code: int) -> None:
        super().__init__(message)
        self.code = code
        self.status_code = status_code


@dataclass(slots=True)
class IndexOutcome:
    document_id: int
    upload_rev: int
    pages: int
    chunks: int
    embedding_model: str
    warnings: list[str] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return {
            "document_id": self.document_id,
            "upload_rev": self.upload_rev,
            "pages": self.pages,
            "chunks": self.chunks,
            "embedding_model": self.embedding_model,
            "warnings": self.warnings,
        }


class IndexService:
    def __init__(self, chunker: Chunker, embeddings: EmbeddingsRegistry, store: ChunkStore) -> None:
        self._chunker = chunker
        self._embeddings = embeddings
        self._store = store

    def index(
        self, meta: DocumentMeta, pages: list[PageText], *, ocr_used: bool, external_allowed: bool = False
    ) -> IndexOutcome:
        started = time.perf_counter()
        current = self._store.current_revision(meta.workspace_id, meta.document_id)
        if current is not None and current > meta.upload_rev:
            INGEST_RESULTS.labels("stale", str(ocr_used).lower()).inc()
            raise IndexingError("stale_revision", f"revision {meta.upload_rev} is older than {current}", 409)
        paged = meta.mime in PAGED_MIMES or len(pages) > 1
        blocks = pages_to_blocks(pages, paged=paged)
        drafts = self._chunker.chunk(blocks)
        if not drafts:
            INGEST_RESULTS.labels("empty", str(ocr_used).lower()).inc()
            raise IndexingError("empty_text", "the document contains no extractable text", 422)
        status = self._embeddings.status()
        embedder = self._embeddings.active(external_allowed=external_allowed)
        warnings: list[str] = []
        if not external_allowed and self._embeddings.is_external():
            warnings.append(f"external embeddings not allowed for this workspace: using {embedder.model_id}")
        elif status.degraded:
            warnings.append(f"embeddings degraded to {embedder.model_id}: {status.reason}")
        vectors = embedder.embed_documents([embedding_text(meta.title, d.section_path, d.content) for d in drafts])
        digest = hashlib.sha256("\x1e".join(p.text for p in pages).encode("utf-8")).hexdigest()
        try:
            stored = self._store.replace_document(
                meta,
                drafts,
                vectors,
                embedder,
                pages=len(pages),
                ocr_used=ocr_used,
                content_sha256=digest,
                warnings=warnings,
            )
        except StaleRevisionError as exc:
            raise IndexingError("stale_revision", str(exc), 409) from exc
        INGEST_RESULTS.labels("indexed", str(ocr_used).lower()).inc()
        INGEST_DURATION.observe(time.perf_counter() - started)
        log.info(
            "document_indexed",
            document_id=meta.document_id,
            upload_rev=meta.upload_rev,
            chunks=stored.chunks,
            pages=len(pages),
            model=stored.embedding_model,
        )
        return IndexOutcome(
            document_id=meta.document_id,
            upload_rev=meta.upload_rev,
            pages=len(pages),
            chunks=stored.chunks,
            embedding_model=stored.embedding_model,
            warnings=stored.warnings,
        )
