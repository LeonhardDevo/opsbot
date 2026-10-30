from __future__ import annotations

import json
import zlib
from typing import Any

from fastapi import APIRouter, Depends, HTTPException, Query, Request, status
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import JSONResponse
from pydantic import ValidationError

from app.api.schemas import IndexIn, MetadataPatchIn, ReembedIn
from app.deps import Container, get_container
from app.ingest.extract import ExtractionError
from app.ingest.index import IndexingError
from app.ingest.pages import PageText
from app.observability import get_logger
from app.privacy.purge import WorkspacePurger
from app.retrieval.store import DocumentMeta, DocumentNotFoundError, DocumentOwnershipError, MetadataPatch
from app.security import require_internal_signature

router = APIRouter(prefix="/v1", dependencies=[Depends(require_internal_signature)], tags=["documents"])
log = get_logger(__name__)

MAX_INDEX_BODY_BYTES = 20 * 1024 * 1024
MAX_INFLATED_BYTES = 200 * 1024 * 1024
DEFAULT_REEMBED_BATCH = 50


def _error(status_code: int, code: str, message: str, **extra: Any) -> JSONResponse:
    return JSONResponse(status_code=status_code, content={"error": code, "message": message, **extra})


def _inflate(body: bytes, encoding: str | None) -> bytes:
    if (encoding or "").strip().lower() != "gzip":
        return body
    inflater = zlib.decompressobj(16 + zlib.MAX_WBITS)
    data = inflater.decompress(body, MAX_INFLATED_BYTES)
    if inflater.unconsumed_tail:
        raise ValueError("the decompressed body is too large")
    return data


@router.post("/documents/{document_id}/extract", response_model=None)
async def extract_document(
    document_id: int,
    request: Request,
    mime: str | None = Query(default=None, max_length=255),
    filename: str | None = Query(default=None, max_length=255),
    max_pages: int | None = Query(default=None, ge=1, le=100_000),
    container: Container = Depends(get_container),
) -> dict[str, Any] | JSONResponse:
    if document_id <= 0:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "document not found")
    data = await request.body()
    limit = container.settings.max_file_mb * 1024 * 1024
    if len(data) > limit:
        return _error(413, "file_too_large", f"the file exceeds {container.settings.max_file_mb} MB")
    try:
        extraction = await run_in_threadpool(
            container.extractor.extract,
            data,
            mime or request.headers.get("content-type"),
            filename,
            max_pages=max_pages,
        )
    except ExtractionError as exc:
        return _error(422, exc.code, str(exc), document_id=document_id)
    return {"document_id": document_id, **extraction.to_dict()}


@router.post("/documents/{document_id}/index", response_model=None)
async def index_document(
    document_id: int, request: Request, container: Container = Depends(get_container)
) -> dict[str, Any] | JSONResponse:
    if document_id <= 0:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "document not found")
    raw = await request.body()
    if len(raw) > MAX_INDEX_BODY_BYTES:
        return _error(413, "body_too_large", "the request body exceeds 20 MB")
    try:
        body = IndexIn.model_validate(json.loads(_inflate(raw, request.headers.get("content-encoding"))))
    except (ValueError, zlib.error, ValidationError) as exc:
        return _error(422, "invalid_body", str(exc)[:500])
    meta = DocumentMeta(
        document_id=document_id,
        workspace_id=body.workspace_id,
        title=body.title.strip(),
        mime=body.mime,
        department_ids=tuple(body.metadata.department_ids),
        roles=tuple(body.metadata.roles),
        tags=tuple(body.metadata.tags),
        version=body.metadata.version,
        effective_date=body.metadata.effective_date,
        approval_status=body.metadata.approval_status,
        archived=body.archived or body.metadata.approval_status == "archived",
        language=body.metadata.language,
        upload_rev=body.upload_rev,
    )
    pages = [PageText(page=p.page, text=p.text) for p in body.pages]
    try:
        outcome = await run_in_threadpool(
            container.indexer.index,
            meta,
            pages,
            ocr_used=body.ocr_used,
            external_allowed=body.ai_external_allowed,
        )
    except IndexingError as exc:
        return _error(exc.status_code, exc.code, str(exc), document_id=document_id)
    except DocumentOwnershipError as exc:
        return _error(409, "workspace_mismatch", str(exc), document_id=document_id)
    return outcome.to_dict()


@router.patch("/documents/{document_id}/metadata", response_model=None)
def patch_metadata(
    document_id: int, body: MetadataPatchIn, container: Container = Depends(get_container)
) -> dict[str, Any] | JSONResponse:
    patch = MetadataPatch(
        workspace_id=body.workspace_id,
        title=body.title.strip(),
        department_ids=tuple(sorted({d for d in body.department_ids if d > 0})),
        roles=tuple(body.roles),
        tags=tuple(body.tags),
        version=(body.version or "").strip() or None,
        effective_date=body.effective_date,
        approval_status=body.approval_status,
        archived=body.archived or body.approval_status == "archived",
    )
    try:
        updated = container.chunk_store.update_metadata(document_id, patch)
    except DocumentNotFoundError as exc:
        return _error(404, "not_found", str(exc), document_id=document_id)
    except DocumentOwnershipError as exc:
        return _error(409, "workspace_mismatch", str(exc), document_id=document_id)
    return {"document_id": document_id, "updated_chunks": updated}


@router.get("/documents/{document_id}/chunks")
def document_chunks(
    document_id: int,
    workspace_id: int = Query(gt=0),
    limit: int = Query(default=20, ge=1, le=200),
    container: Container = Depends(get_container),
) -> dict[str, Any]:
    chunks = container.chunk_store.preview(workspace_id, document_id, limit)
    return {"document_id": document_id, "chunks": chunks}


@router.get("/documents/{document_id}/chunks/{chunk_id}")
def chunk_with_context(
    document_id: int,
    chunk_id: int,
    workspace_id: int = Query(gt=0),
    context: int = Query(default=1, ge=0, le=5),
    container: Container = Depends(get_container),
) -> dict[str, Any]:
    found = container.chunk_store.chunk_context(workspace_id, document_id, chunk_id, context)
    if found is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "chunk not found")
    return found


@router.delete("/documents/{document_id}")
def delete_document(
    document_id: int,
    workspace_id: int = Query(gt=0),
    container: Container = Depends(get_container),
) -> dict[str, Any]:
    try:
        deleted = container.chunk_store.delete_document(workspace_id, document_id)
    except DocumentOwnershipError as exc:
        raise HTTPException(status.HTTP_409_CONFLICT, str(exc)) from exc
    return {"document_id": document_id, "deleted_chunks": deleted}


@router.post("/workspaces/{workspace_id}/reembed")
def reembed_workspace(
    workspace_id: int, body: ReembedIn | None = None, container: Container = Depends(get_container)
) -> dict[str, Any]:
    if workspace_id <= 0:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "workspace not found")
    request = body or ReembedIn()
    status_info = container.embeddings.status()
    embedder = container.embeddings.active(external_allowed=request.ai_external_allowed)
    batch = container.chunk_store.reembed_documents(
        workspace_id,
        embedder,
        cursor=request.cursor,
        limit=request.batch_documents or DEFAULT_REEMBED_BATCH,
        batch_size=container.settings.embeddings_batch_size,
    )
    if request.batch_documents is None:
        total, cursor = batch.chunks, batch.next_cursor
        documents = batch.documents
        while cursor is not None:
            more = container.chunk_store.reembed_documents(
                workspace_id,
                embedder,
                cursor=cursor,
                limit=DEFAULT_REEMBED_BATCH,
                batch_size=container.settings.embeddings_batch_size,
            )
            total, cursor, documents = total + more.chunks, more.next_cursor, documents + more.documents
        return {
            "workspace_id": workspace_id,
            "chunks": total,
            "documents": documents,
            "next_cursor": None,
            "embedding_model": embedder.model_id,
            "degraded": status_info.degraded,
        }
    return {
        "workspace_id": workspace_id,
        "chunks": batch.chunks,
        "documents": batch.documents,
        "next_cursor": batch.next_cursor,
        "embedding_model": embedder.model_id,
        "degraded": status_info.degraded,
    }


@router.delete("/workspaces/{workspace_id}")
def purge_workspace(workspace_id: int, container: Container = Depends(get_container)) -> dict[str, Any]:
    if workspace_id <= 0:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "workspace not found")
    counts = WorkspacePurger(container.session_factory).purge(workspace_id)
    log.info("workspace_purged", workspace_id=workspace_id, **counts.to_dict())
    return {"workspace_id": workspace_id, "purged": counts.to_dict()}
