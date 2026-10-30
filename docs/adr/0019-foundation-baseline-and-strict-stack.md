# ADR-0019 — Foundation baseline pinned; OpsBot owns its FastAPI service (strict stack)

- Status: accepted
- Date: 2026-10-22 (plan review 2)
- Implements: PLATFORM.md §0.10 (strict per-product stack)
- Related: ADR-0006, ADR-0008, DOMAIN_CONTRACT §0.3

## Context

M0 said "clone the foundation from payflow-guard" without a source revision. payflow-guard is moving:
its ADR-0014 deletes `apps/ai` and moves RAG into the Node service (`src/rag`, `WORKER_ROLES`), and its
ADR-0010 switches to per-pair internal keys. PLATFORM §0.10 gives OpsBot a different stack from PayFlow:

| Brief component | OpsBot location |
|---|---|
| Laravel + Vue SaaS (Inertia, shadcn-vue) | `apps/web` |
| Telegram bot (multi-bot registry: each company connects its own bot token) | `apps/worker` (grammY) |
| Node.js + BullMQ/Redis: outbox relay, notifications, reminders, document processing + OCR, indexing and re-embedding jobs | `apps/worker` |
| FastAPI + PostgreSQL + pgvector: chunking, embeddings, hybrid search, confidence gate, tools, answers | `apps/ai` |
| Playwright (+ axe) | `e2e` |

## Decision

1. **Pinned source.** M0 copies the foundation from payflow-guard commit **`002cb4e`** (`apps/ai`
   tracked, 83 files; no `apps/worker/src/rag`), *not* from the working tree or a later commit. The copy
   is a plain file copy (no git history, no remote); after M0 OpsBot owns all three apps and never
   merges from PayFlow. Foundation fixes PayFlow makes later are ported by hand when wanted.
2. **Not cloned** (M0 exit check): PayFlow `docs/**`, `design-system/payflow-guard/**`, any PayFlow
   domain code (`app/Domain/PayFlow*`, invoice/reminder modules), `apps/worker/src/rag/**`,
   `WORKER_ROLES`, `contracts/` from PayFlow's uncommitted tree, ADR-0010 per-pair key code,
   PayFlow brand assets and seeders. `docs/FOUNDATION_CONTRACT.md` is copied from `002cb4e` and marked
   "as of 002cb4e; OpsBot overrides in DOMAIN_CONTRACT §0.2".
3. **AI service.** `AI_URL=http://127.0.0.1:8202`, `X-Service: ai` = the FastAPI app. OpsBot keeps the
   PLATFORM §4.4 contract, with the deltas in DOMAIN_CONTRACT §10 (and ADR-0008: indexing endpoint).
4. **Internal signing.** OpsBot keeps PLATFORM §4.1: one `INTERNAL_API_SECRET` in all three `.env`, plus
   **caller-scoped routes** (each internal route lists its allowed `X-Service` values, e.g. bot verify =
   `web` only, `opsbot/routing/resolve` = `ai` only). PayFlow ADR-0010 (per-pair keys) is **not** adopted:
   its threat (a parser exploit in a service that holds the key) is handled in OpsBot by the secretless
   extractor sandbox (ADR-0008 §2). Revisit if a service that holds the key starts parsing untrusted input.
5. **Rename at M0:** product config (`config/product.php` slug `opsbot`, name, ports from PLATFORM §2:
   web 8200, vite 5174, worker 8201, ai 8202, PG 5442, Redis 6392, Mailpit 1032/8231, stripe-mock 12121),
   `REDIS_PREFIX=opsbot:`, DB `opsbot`, brand placeholder replaced by MASTER tokens in M6.

## Consequences

- The FastAPI service, `content_terms()/coverage()` and the calibrated gate that ADR-0006 and
  DOMAIN_CONTRACT §10.7 rely on exist in OpsBot regardless of PayFlow's port to Node.
- The M0 exit criteria include the source commit `002cb4e` recorded in the `FOUNDATION_CONTRACT.md`
  header and a check proving the excluded paths are absent.
