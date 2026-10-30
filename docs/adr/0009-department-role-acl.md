# ADR-0009 — Department & role ACL by ids, with explicit cache invalidation

- Status: accepted
- Date: 2026-10-22
- Overrides: PLATFORM.md §4.6 (department as a string, roles-only filter); FOUNDATION_CONTRACT
  `KbDocument.department: string|null`
- Related: ADR-0004, ADR-0005, DOMAIN_CONTRACT §2.4, §3.1, §3.3, §10.1

## Context

The foundation stores `department` as a single string and `roles[]` on documents; the `/v1/chat` user
carries only `role` and `permissions`. OpsBot filters by department **and** role. Renaming a department
would desync chunk metadata, and the worker caches the account context, so a moved employee would keep
old access until the TTL expires.

## Decision

1. `departments(id, workspace_id, name, code, manager_user_id?, archived_at)`; membership in
   `department_user(department_id, user_id, is_primary)`.
2. Documents and chunks store **`department_ids int[]`** (GIN-indexed) and `roles text[]`
   (values from the role enum `owner|admin|manager|employee`). Names are never copied into chunks, so
   renames need no re-sync.
3. **Visibility rule** (same function in web policies and ai SQL):
   ```
   visible(doc, user) =
       user.privileged                                   -- owner/admin (ability knowledge.view_all)
    OR ( (cardinality(doc.department_ids) = 0 OR doc.department_ids && user.department_ids)
     AND (cardinality(doc.roles) = 0 OR user.role = ANY(doc.roles)) )
   ```
   combined with the approval filter of ADR-0005. Truth-table tests (8 rows: dept empty/match/miss ×
   roles empty/match/miss × privileged) in Pest (`DocumentVisibility`) and pytest (SQL), with identical
   fixtures.
4. `/v1/chat` and `/v1/search` user objects gain `department_ids: int[]` and `privileged: bool`.
5. Metadata edits → outbox `document.metadata_changed {document_id, workspace_id, title, department_ids,
   roles, tags, version, effective_date, approval_status, archived}` → worker → ai
   `PATCH /v1/documents/{id}/metadata` which updates `rag.documents` and all `rag.chunks` rows in one
   statement (generated tsvector columns refresh automatically on title change). No re-embedding.
6. Role or department membership change, offboarding, and unlink publish
   **`telegram.context.invalidate {workspace_id, user_id, keys:[{bot_key, telegram_user_id}]}`** (web
   resolves the keys) → worker deletes `account:{bot_key}:{telegram_user_id}` in Redis. The 60 s TTL is
   only a safety net. Vitest: invalidation removes the key; Pest: every mutation path publishes it.
7. Managers' **department scope** for web records (incidents, tasks, unanswered questions, query log,
   employees): a manager acts on records whose `department_id ∈` the manager's `department_ids`.
   Implemented once in `DepartmentScope` and used by all policies.

## Consequences

- Department archive (not delete) keeps history; documents referencing an archived department stay
  visible only to privileged users until edited (UI warns).
- A user without departments sees only documents with empty `department_ids`.
