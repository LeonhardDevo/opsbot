# ADR-0008 — Document processing and OCR in the Node worker (BullMQ); indexing in FastAPI

- Status: accepted (revised 2026-10-22, plan review 2 — supersedes the first version, which ran parsing
  and OCR inside FastAPI in an in-process executor with `rag.ingest_jobs`)
- Date: 2026-10-22
- Overrides: PLATFORM.md §3 ("ai owns document parsing/OCR") and §4.4 `POST /v1/documents/{id}/ingest`
  (fetch + parse + OCR in the ai service) — for OpsBot only, per the product brief and PLATFORM §0.10
  ("Node.js + BullMQ/Redis: document processing, OCR, indexing, re-embedding; FastAPI: chunking,
  embeddings, search")
- Related: ADR-0005, ADR-0012 (page limits), DOMAIN_CONTRACT §8, §9.2, §10.5, §10.6

## Context

The brief assigns document processing, OCR, indexing jobs and re-embedding to Node + BullMQ, and
chunking, embeddings and search to FastAPI. A 150-page scanned PDF under Tesseract takes minutes. The
first version of this ADR ran OCR in a Python background executor: a second, non-durable queue (jobs lost
on restart, no BullMQ retry/backoff, only polling showed progress). BullMQ already gives durable jobs,
retries, backoff, progress and Bull Board.

## Decision

1. **Roles.** The worker *processes* (fetch, text extraction, OCR, progress, retries); the ai service
   *indexes* (chunk, embed, store, search). Tesseract lives in the worker image only; the ai image has no
   OCR dependencies.
2. **`document.extract`** (queue `documents`, job id `extract:{document_id}:{upload_rev}`, concurrency 2,
   `attempts 3`, exponential backoff 5 s, job timeout 25 min):
   - fetches the file from web `GET /internal/v1/documents/{id}/file` (signed, `X-Service: worker`);
   - PDF text layer via `pdfjs-dist` (legacy Node build); DOCX via `mammoth` (raw text, headings kept as
     `#` lines); TXT/MD as is; HTML via a sanitizing text extractor; images and PDF pages with < 20
     characters of text are rasterized (`pdfjs-dist` + `@napi-rs/canvas`, 300 dpi) and OCRed;
   - **sandbox:** all parsing of untrusted files (pdfjs, mammoth, HTML, rasterizing) runs in a forked
     extractor process (`src/documents/extractor/child.ts`, `child_process.fork` with an **empty
     environment** except `TESSDATA_PREFIX`, `OMP_THREAD_LIMIT=1`, `--max-old-space-size=512`, IPC only,
     no network code, killed on timeout). The parent job holds `INTERNAL_API_SECRET`, fetches the file
     and posts results; the child receives bytes and returns pages, so a parser exploit cannot read the
     signing key (this is why the single shared key is kept, ADR-0019);
   - OCR = system `tesseract` CLI with `-l rus+eng --psm 1`, spawned by the child with `execFile` (no
     shell), a 60 s per-page timeout, a temp directory deleted in `finally`;
   - enforces `max_pages_per_document` (from the payload, ADR-0012) before OCR → `too_many_pages`;
   - reports progress to web `POST /internal/v1/documents/{id}/status {status:'processing', pages_done,
     pages_total, ocr_used}` at most every 5 pages and `job.updateProgress()` for Bull Board;
   - result `{pages:[{page, text}], ocr_used, language_guess}` is stored in Redis
     `extract:{document_id}:{upload_rev}` (TTL 24 h, never in job data/logs) and the job enqueues
     `document.index` with the same rev.
3. **`document.index`** (job id `index:{document_id}:{upload_rev}`, attempts 5, backoff 2 s) → ai
   `POST /v1/documents/{id}/index {workspace_id, upload_rev, title, mime, metadata, pages:[{page,text}],
   ocr_used}` (body ≤ 20 MB, gzip). The ai service chunks, embeds and **replaces the document's chunks in
   one transaction**; the call is idempotent by `(document_id, upload_rev)` (a stale rev returns
   `409 stale_revision` and the job finishes without effect). Response `{pages, chunks}`; the worker then
   reports `indexed` (or `failed` + `error_code`) to web. Embedding a 500-page document with `hash` or
   `local` embeddings stays within the 120 s HTTP timeout; larger models are a v1 concern
   (batching per page range).
4. **Re-embedding** (`workspace.reembed`) stays a worker job that calls ai
   `POST /v1/workspaces/{id}/reembed` per document batch (cursor), so progress and retries are BullMQ's.
5. **`upload_rev`** = `documents.upload_rev` (int, incremented on upload and on retry); web passes it in
   `document.uploaded`. Old revs never overwrite newer chunks.
6. **Removed** from the first version: `rag.ingest_jobs`, the advisory lock, the Python background
   executor, `GET /v1/documents/{id}/ingest-status`, the `document.ingest.watch` job and `ingest_lost`.
7. **Error codes** (localized in web): `too_many_pages | unsupported_type | empty_text | ocr_failed |
   timeout | file_missing | index_failed`. A job that exhausts its attempts reports `failed` with the last
   code; "Retry" in the UI increments `upload_rev` and re-publishes `document.uploaded`.
8. **Tesseract data.** Russian OCR needs `rus.traineddata`. Worker Dockerfile installs
   `tesseract-ocr tesseract-ocr-rus tesseract-ocr-eng`. Local dev (the dev machine has `eng+osd` only):
   README documents downloading `rus.traineddata` (tessdata_fast) into a user-writable directory and
   setting `TESSDATA_PREFIX` in `apps/worker/.env`; `GET /healthz` reports `ocr: {available, langs[]}`
   and the worker refuses OCR with `ocr_failed` + a clear log line when `rus` is missing (text-layer
   documents still work). Tests with OCR are skipped (not failed) when `tesseract` is absent, and CI
   installs it.

## Consequences

- One durable queue for all document work; restart-safe; visible in Bull Board.
- Vitest: extractor per mime (fixtures: text PDF, scanned PDF page, DOCX, TXT); page limit before OCR;
  child process gets no env secrets; progress throttling; stale rev ignored; retry increments rev.
- pytest: `/v1/documents/{id}/index` atomic replace (index twice → identical `chunks_count` and ordinals),
  stale rev 409, empty pages → `empty_text`.
