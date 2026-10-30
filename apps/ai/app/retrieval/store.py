from __future__ import annotations

from collections.abc import Sequence
from dataclasses import dataclass, field
from datetime import UTC, date, datetime
from typing import Any

from sqlalchemy import delete, func, select, update
from sqlalchemy.orm import Session, sessionmaker

from app.embeddings.base import Embedder
from app.ingest.chunker import ChunkDraft
from app.models import Chunk, Document
from app.retrieval.indexes import ensure_hnsw_index


@dataclass(frozen=True, slots=True)
class DocumentMeta:
    document_id: int
    workspace_id: int
    title: str
    mime: str
    department_ids: tuple[int, ...] = ()
    roles: tuple[str, ...] = ()
    tags: tuple[str, ...] = ()
    version: str | None = None
    effective_date: date | None = None
    approval_status: str = "draft"
    archived: bool = False
    language: str | None = None
    upload_rev: int = 0


@dataclass(frozen=True, slots=True)
class MetadataPatch:
    workspace_id: int
    title: str
    department_ids: tuple[int, ...]
    roles: tuple[str, ...]
    tags: tuple[str, ...]
    version: str | None
    effective_date: date | None
    approval_status: str
    archived: bool


@dataclass(slots=True)
class StoredDocument:
    document_id: int
    chunks: int
    embedding_model: str
    warnings: list[str] = field(default_factory=list)


@dataclass(frozen=True, slots=True)
class ReembedBatch:
    chunks: int
    documents: int
    next_cursor: int | None


def embedding_text(title: str, section_path: str | None, content: str) -> str:
    head = f"{title}\n{section_path}" if section_path else title
    return f"{head}\n{content}"


class DocumentOwnershipError(PermissionError):
    pass


class StaleRevisionError(RuntimeError):
    def __init__(self, current: int, requested: int) -> None:
        super().__init__(f"revision {requested} is older than the indexed revision {current}")
        self.current = current
        self.requested = requested


class DocumentNotFoundError(LookupError):
    pass


def _chunk_row(chunk: Chunk) -> dict[str, Any]:
    return {
        "chunk_id": chunk.id,
        "ordinal": chunk.ordinal,
        "page": chunk.page,
        "section_path": chunk.section_path,
        "content": chunk.content,
    }


class ChunkStore:
    def __init__(self, session_factory: sessionmaker[Session]) -> None:
        self._session_factory = session_factory

    def mark_document(self, meta: DocumentMeta, *, status: str, error: str | None = None) -> None:
        with self._session_factory() as session:
            doc = self._upsert_document(session, meta)
            doc.status = status
            doc.error = error
            session.commit()

    def current_revision(self, workspace_id: int, document_id: int) -> int | None:
        with self._session_factory() as session:
            doc = session.get(Document, document_id)
            if doc is None:
                return None
            if doc.workspace_id != workspace_id:
                raise DocumentOwnershipError("document belongs to another workspace")
            return doc.upload_rev

    def replace_document(
        self,
        meta: DocumentMeta,
        drafts: Sequence[ChunkDraft],
        vectors: Sequence[Sequence[float]],
        embedder: Embedder,
        *,
        pages: int | None,
        ocr_used: bool,
        content_sha256: str,
        warnings: Sequence[str] = (),
    ) -> StoredDocument:
        if len(drafts) != len(vectors):
            raise ValueError("drafts and vectors must have the same length")
        archived_at = datetime.now(UTC) if meta.archived else None
        with self._session_factory() as session:
            existing = session.get(Document, meta.document_id, with_for_update=True)
            if (
                existing is not None
                and existing.workspace_id == meta.workspace_id
                and existing.upload_rev > meta.upload_rev
            ):
                session.rollback()
                raise StaleRevisionError(existing.upload_rev, meta.upload_rev)
            doc = self._upsert_document(session, meta)
            session.execute(delete(Chunk).where(Chunk.document_id == meta.document_id))
            ensure_hnsw_index(session, embedder.model_id, embedder.dims)
            session.add_all(
                Chunk(
                    document_id=meta.document_id,
                    workspace_id=meta.workspace_id,
                    ordinal=draft.ordinal,
                    content=draft.content,
                    title=meta.title,
                    page=draft.page,
                    section_path=draft.section_path,
                    department_ids=list(meta.department_ids),
                    approval_status=meta.approval_status,
                    archived_at=archived_at,
                    roles=list(meta.roles),
                    tags=list(meta.tags),
                    version=meta.version,
                    effective_date=meta.effective_date,
                    token_count=draft.token_count,
                    embedding=list(vector),
                    embedding_model=embedder.model_id,
                    embedding_dims=embedder.dims,
                )
                for draft, vector in zip(drafts, vectors, strict=True)
            )
            doc.status = "indexed"
            doc.pages = pages
            doc.chunks_count = len(drafts)
            doc.ocr_used = ocr_used
            doc.error = None
            doc.embedding_model = embedder.model_id
            doc.content_sha256 = content_sha256
            doc.warnings = list(warnings)
            doc.indexed_at = datetime.now(UTC)
            doc.upload_rev = meta.upload_rev
            session.commit()
        return StoredDocument(meta.document_id, len(drafts), embedder.model_id, list(warnings))

    def update_metadata(self, document_id: int, patch: MetadataPatch) -> int:
        with self._session_factory() as session:
            doc = session.get(Document, document_id, with_for_update=True)
            if doc is None:
                raise DocumentNotFoundError(f"document {document_id} is not indexed")
            if doc.workspace_id != patch.workspace_id:
                raise DocumentOwnershipError("document belongs to another workspace")
            archived_at = (doc.archived_at or datetime.now(UTC)) if patch.archived else None
            doc.title = patch.title
            doc.department_ids = list(patch.department_ids)
            doc.roles = list(patch.roles)
            doc.tags = list(patch.tags)
            doc.version = patch.version
            doc.effective_date = patch.effective_date
            doc.approval_status = patch.approval_status
            doc.archived_at = archived_at
            result = session.execute(
                update(Chunk)
                .where(Chunk.document_id == document_id)
                .values(
                    title=patch.title,
                    department_ids=list(patch.department_ids),
                    roles=list(patch.roles),
                    tags=list(patch.tags),
                    version=patch.version,
                    effective_date=patch.effective_date,
                    approval_status=patch.approval_status,
                    archived_at=archived_at,
                )
            )
            session.commit()
            return int(getattr(result, "rowcount", 0) or 0)

    def preview(self, workspace_id: int, document_id: int, limit: int = 20) -> list[dict[str, Any]]:
        with self._session_factory() as session:
            rows = session.scalars(
                select(Chunk)
                .where(Chunk.document_id == document_id, Chunk.workspace_id == workspace_id)
                .order_by(Chunk.ordinal)
                .limit(limit)
            ).all()
            return [_chunk_row(chunk) for chunk in rows]

    def chunk_context(
        self, workspace_id: int, document_id: int, chunk_id: int, context: int = 1
    ) -> dict[str, Any] | None:
        with self._session_factory() as session:
            target = session.get(Chunk, chunk_id)
            if target is None or target.document_id != document_id or target.workspace_id != workspace_id:
                return None
            neighbours = session.scalars(
                select(Chunk)
                .where(
                    Chunk.document_id == document_id,
                    Chunk.ordinal >= target.ordinal - context,
                    Chunk.ordinal <= target.ordinal + context,
                    Chunk.id != target.id,
                )
                .order_by(Chunk.ordinal)
            ).all()
            return {
                "before": [_chunk_row(c) for c in neighbours if c.ordinal < target.ordinal],
                "chunk": _chunk_row(target),
                "after": [_chunk_row(c) for c in neighbours if c.ordinal > target.ordinal],
            }

    def delete_document(self, workspace_id: int, document_id: int) -> int:
        with self._session_factory() as session:
            doc = session.get(Document, document_id)
            if doc is None:
                return 0
            if doc.workspace_id != workspace_id:
                raise DocumentOwnershipError("document belongs to another workspace")
            count = session.scalar(select(func.count()).select_from(Chunk).where(Chunk.document_id == document_id)) or 0
            session.delete(doc)
            session.commit()
            return int(count)

    def reembed_workspace(self, workspace_id: int, embedder: Embedder, batch_size: int = 64) -> int:
        total = 0
        cursor: int | None = 0
        while cursor is not None:
            batch = self.reembed_documents(workspace_id, embedder, cursor=cursor, limit=50, batch_size=batch_size)
            total += batch.chunks
            cursor = batch.next_cursor
        return total

    def reembed_documents(
        self, workspace_id: int, embedder: Embedder, *, cursor: int, limit: int, batch_size: int = 64
    ) -> ReembedBatch:
        with self._session_factory() as session:
            ensure_hnsw_index(session, embedder.model_id, embedder.dims)
            session.commit()
            document_ids = list(
                session.scalars(
                    select(Document.id)
                    .where(Document.workspace_id == workspace_id, Document.id > cursor)
                    .order_by(Document.id)
                    .limit(limit + 1)
                ).all()
            )
        has_more = len(document_ids) > limit
        document_ids = document_ids[:limit]
        total = 0
        for document_id in document_ids:
            total += self._reembed_document(document_id, embedder, batch_size)
        return ReembedBatch(
            chunks=total,
            documents=len(document_ids),
            next_cursor=document_ids[-1] if has_more and document_ids else None,
        )

    def _reembed_document(self, document_id: int, embedder: Embedder, batch_size: int) -> int:
        total = 0
        last_id = 0
        while True:
            with self._session_factory() as session:
                rows = session.execute(
                    select(Chunk.id, Chunk.title, Chunk.section_path, Chunk.content)
                    .where(Chunk.document_id == document_id, Chunk.id > last_id)
                    .order_by(Chunk.id)
                    .limit(batch_size)
                ).all()
                if not rows:
                    break
                vectors = embedder.embed_documents([embedding_text(r.title, r.section_path, r.content) for r in rows])
                for row, vector in zip(rows, vectors, strict=True):
                    session.execute(
                        update(Chunk)
                        .where(Chunk.id == row.id)
                        .values(embedding=list(vector), embedding_model=embedder.model_id, embedding_dims=embedder.dims)
                    )
                session.commit()
                total += len(rows)
                last_id = rows[-1].id
        with self._session_factory() as session:
            session.execute(
                update(Document)
                .where(Document.id == document_id, Document.chunks_count > 0)
                .values(embedding_model=embedder.model_id)
            )
            session.commit()
        return total

    @staticmethod
    def _upsert_document(session: Session, meta: DocumentMeta) -> Document:
        doc = session.get(Document, meta.document_id, with_for_update=True)
        if doc is None:
            doc = Document(id=meta.document_id, workspace_id=meta.workspace_id)
            session.add(doc)
        elif doc.workspace_id != meta.workspace_id:
            raise DocumentOwnershipError("document belongs to another workspace")
        doc.title = meta.title
        doc.mime = meta.mime
        doc.department_ids = list(meta.department_ids)
        doc.approval_status = meta.approval_status
        doc.archived_at = (doc.archived_at or datetime.now(UTC)) if meta.archived else None
        doc.language = meta.language
        doc.roles = list(meta.roles)
        doc.tags = list(meta.tags)
        doc.version = meta.version
        doc.effective_date = meta.effective_date
        return doc
