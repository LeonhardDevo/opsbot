# ADR-0005 — Only approved, effective documents are answerable

- Status: accepted
- Date: 2026-10-22
- Overrides: FOUNDATION_CONTRACT §3 (`/knowledge` uploads are answerable immediately)
- Related: ADR-0006, ADR-0008, ADR-0009, DOMAIN_CONTRACT §3.1 `documents`, §3.3, §4.1

## Context

The idea promises answers "только по утверждённым документам". In the foundation every upload is
indexed and answerable as soon as it is processed. Outdated and conflicting regulations are a risk the
idea itself lists.

## Decision

### Two independent axes on `documents`
- **Processing** (foundation): `status` = `queued | processing | indexed | failed`.
- **Approval** (new): `approval_status` = `draft | approved | archived`, plus `approved_by`,
  `approved_at`, `effective_date` (date, workspace calendar), `review_due_at` (date),
  `supersedes_document_id` (nullable FK → documents), `archived_at`, `archived_by`,
  `archive_reason` enum `manual | superseded`, `review_notified_on` (date, dedupe).

### Transitions (permission `knowledge.approve` = owner, admin)
| From | To | Guard | Side effects (one transaction) |
|---|---|---|---|
| draft | approved | `status = indexed`; `effective_date` given (default: today in workspace tz); `review_due_at` default `effective_date + 365 days` | `approved_by/at`; if `supersedes_document_id` set and successor `effective_date <= today` → archive the predecessor (`archive_reason = superseded`); outbox `document.metadata_changed` for each changed doc; audit `document.approved` (+ `document.archived`) |
| draft | archived | — | audit `document.archived` |
| approved | archived | — | outbox `document.metadata_changed`; audit |
| archived | * | never (terminal; re-upload creates a new document) | — |

- A new file for an existing regulation is uploaded as a **new document** with
  `supersedes_document_id`; files of approved documents are immutable.
- Successor with a future `effective_date`: the predecessor stays approved until that date; the
  hourly `opsbot.documents.tick` archives predecessors whose successor became effective (workspace
  tz), audit `document.archived` with reason `superseded`. Until then retrieval returns only the
  predecessor (the successor is filtered by date).
- Metadata edits on approved documents (title, departments, roles, tags, review date) require
  `knowledge.approve`, are audited (`document.metadata_updated`, before/after diff) and publish
  `document.metadata_changed` (no re-embedding).
- Review reminder: when `review_due_at <= today` and `review_notified_on` is null or older than 30 days,
  the hourly tick notifies `approved_by` (fallback: owners/admins) by Telegram/e-mail
  (`opsbot.document.review_due`) and sets `review_notified_on`. The knowledge page shows a
  "Review overdue" badge.

### Retrieval filter (ai, SQL, always applied together with ACL — ADR-0009)
```sql
c.workspace_id = :workspace_id
AND c.approval_status = 'approved'
AND c.effective_date <= :today          -- 'today' = context.today sent by the caller (workspace tz)
AND c.archived_at IS NULL
```
Drafts are indexed (admins preview chunks on `knowledge/Show` and can "Test a question" against a draft
via `/v1/search` with `include_drafts=true`, permission `knowledge.approve`), but **never** cited by
`/v1/chat`.

## Consequences

- Required tests (pytest): a draft, a future-dated, and an archived document with a perfect lexical
  match are never cited (and, when it is the only matching document, the question is refused);
  approving flips it on via
  `PATCH /v1/documents/{id}/metadata` without re-embedding. Pest: transition table (allowed + forbidden
  per role), superseding archives atomically, future successor archived by the tick on the right local
  date (fixed clock).
- The starter pack (ADR-0001) imports as drafts; the demo seeder approves them explicitly.

## Alternatives rejected

- **Index only approved documents** — admins could not preview chunks or test drafts; approval would
  trigger re-ingest (slow for OCR). Filtering by columns is instant.
- **Versioning inside one document row** — complicates citations ("which version did the bot quote?");
  separate rows give stable `document_id + version` in `cited_document_versions` (ADR-0015).
