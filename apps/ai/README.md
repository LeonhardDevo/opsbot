# AI / RAG service

FastAPI service that extracts text from documents (with Tesseract OCR), chunks and embeds them, answers
questions with hybrid retrieval (pgvector HNSW + Postgres FTS fused with RRF), runs the LLM provider manager
and the read-only domain tools of OpsBot, and accounts AI usage. Contracts: `PLATFORM.md` §4.1, §4.4–§4.6 and
`docs/DOMAIN_CONTRACT.md` §3.3, §10.

## Quick start

```bash
docker compose up -d                       # from the project root: pgvector on 5442
cd apps/ai
cp .env.example .env                       # every value has an offline default
uv sync                                    # Python 3.12 via uv
uv run alembic upgrade head                # schema `rag`
uv run uvicorn app.main:app --port 8202    # /healthz, /readyz, /metrics, /docs (non-production)
```

Tests (`opsbot_test` on 5442; database tests are skipped with a clear reason when it is down):

```bash
uv run pytest                              # includes the golden set tests/golden/cleaning.yaml
uv run ruff check . && uv run ruff format --check . && uv run mypy app tests
```

The golden set reads the Cleaning starter pack from `STARTER_PACK_DIR`
(default `apps/web/resources/starter-packs/cleaning`).

## API

| Method & path | Purpose |
|---|---|
| `POST /v1/chat` | answer with citations, `confidence_band`, `answer_kind`, `refusal_reason`, `disclaimers`, proposed `actions` |
| `POST /v1/search` | ranked chunks for a viewer (`user{role, department_ids, privileged}`, `include_drafts`) |
| `POST /v1/documents/{id}/extract` | raw file bytes in → `{pages:[{page,text}], pages_total, ocr_used, language_guess}`; errors `too_many_pages`, `unsupported_type`, `empty_text`, `ocr_failed` |
| `POST /v1/documents/{id}/index` | pages in (JSON, optionally gzip) → chunks, embeds and replaces the document's chunks in one transaction; `409 stale_revision`, `422 empty_text` |
| `PATCH /v1/documents/{id}/metadata` | approval, archive, departments, roles, tags, version, effective date — no re-embedding |
| `GET /v1/documents/{id}/chunks`, `GET /v1/documents/{id}/chunks/{chunk_id}?context=1` | previews and neighbours |
| `DELETE /v1/documents/{id}` | drop a document and its chunks |
| `POST /v1/workspaces/{id}/reembed` | `{cursor, batch_documents}` → re-embeds the next batch, returns `next_cursor` |
| `/v1/admin/ai/*` | providers, models, keys, settings, test call, usage |

All `/v1/*` routes require the signed internal headers (`X-Service`, `X-Signature`).

## How an answer is produced

1. **Data intents first.** Deterministic RU/EN rules (`app/tools/intents.py`) detect "who handles …",
   "my tasks" and "incident № N". A match runs the read-only tool (`who_handles`, `my_tasks`,
   `incident_status`, all reading web's internal API) and renders a code template naming its source
   (`answer_kind = tool_data`). No LLM call is made.
2. **Retrieval** in the caller's workspace with the visibility rule: privileged users see everything;
   others see chunks whose `department_ids` are empty or intersect theirs and whose `roles` are empty or
   contain their role. Only `approved`, not archived chunks with `effective_date <= context.today` are
   searched. Newer versions of the same document family win and the answer notes the conflict.
3. **Gate.** Hit confidence = 0.55 × calibrated cosine + 0.45 × lexical coverage; answer confidence is the
   best of the top 3. Below `context.min_confidence` the service refuses before any provider call.
4. **Agent loop** (max 4 tool rounds) with sources wrapped as untrusted `<documents>`. When the workspace does
   not allow external models (`ai_external_allowed = false`) the local extractive provider is used; otherwise
   e-mails, phone numbers, card numbers and IBANs are masked before the text leaves the service.
5. **Post-gate.** A document answer must keep the confidence and cite at least one source, otherwise it is
   replaced by the fixed refusal and an `escalate` action. Chunks tagged `compensation` append the
   "final amount is confirmed by your manager" line.
6. **Proposed actions** come only from the user's message (`app/actions/validate.py`): incident categories by
   keyword stems, task requests, escalation on refusal, the top source on answers. Payloads are validated
   (`app/actions/schemas.py`), at most four, never executed here.

## Providers

`mock` (default, offline, deterministic) · `anthropic` · `openai` · `deepseek` · `openrouter` · `nvidia` ·
`opencode-zen` · `opencode-go` · `ollama`. A key saved in the admin UI (encrypted with `AI_SETTINGS_KEY`)
overrides the env key. 404/403 → fallback model; 429/5xx → backoff with `retry-after`, fallback after three
attempts. Every call is written to `rag.ai_usage`.

## Layout

| Path | Responsibility |
|---|---|
| `app/api/` | HTTP routers |
| `app/agent/` | pipeline, loop, templates for tool data, disclaimers, redaction, prompts, citations |
| `app/actions/` | proposed action schemas and rules |
| `app/tools/` | `search_documents`, `who_handles`, `my_tasks`, `incident_status`, intent rules |
| `app/ingest/` | parsers + OCR (`extract.py`), page ↔ block conversion, chunker, indexing (`index.py`) |
| `app/retrieval/` | visibility filters, hybrid search, conflicts, chunk store |
| `app/prompts/` | `system.{ru,en}.md`, `domain.{ru,en}.md` |
| `migrations/` | Alembic, schema `rag` |

---

## Кратко по-русски

Сервис ответов по документам: извлечение текста и OCR (Tesseract `rus+eng`), разбиение на чанки,
эмбеддинги, гибридный поиск с учётом отделов, ролей, утверждения и даты вступления в силу, детерминированный
порог уверенности, ответы только с цитатами и отказ с кнопкой «Передать менеджеру». Вопросы «кому передать»,
«мои задачи» и «инцидент № N» обрабатываются правилами и шаблонами без обращения к модели. Действия
(инцидент, задача, эскалация) сервис только предлагает — выполняет их человек нажатием кнопки.
Запуск: `uv sync`, `uv run alembic upgrade head`, `uv run uvicorn app.main:app --port 8202`. Тесты: `uv run pytest`.
