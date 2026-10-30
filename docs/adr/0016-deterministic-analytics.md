# ADR-0016 — Deterministic analytics (no LLM topic clustering)

- Status: accepted
- Date: 2026-10-22
- Related: DOMAIN_CONTRACT §13, design-system `pages/analytics.md`

## Context

"Статистика запросов" and an implied "top topics" view were undefined. LLM or embedding clustering is
non-deterministic and untestable; day buckets differ with the timezone.

## Decision

1. Every KPI is a SQL query over `ai_queries`, `unanswered_questions`, `incidents`, `tasks`,
   `documents`, bucketed by `date_trunc('day', created_at AT TIME ZONE :workspace_tz)`. Periods: last
   7 / 30 / 90 days ending today (workspace tz). Exact definitions in DOMAIN_CONTRACT §13.
2. **Top unanswered questions** are grouped deterministically: candidates = refused or escalated queries
   in the period (max 2000, newest first); normalized text `question_normalized`
   (`lower(unaccent(question))`, whitespace collapsed); greedy grouping in PHP: iterate candidates
   ordered by `(question_normalized, id)`, attach a candidate to the first existing group whose
   representative has `similarity(rep, candidate) >= 0.6` (pg_trgm, computed in SQL in one batch per
   period), else start a new group. Groups sorted by size desc, then representative text. Same input →
   same output.
3. "Documents never cited in 30 days" = approved, not archived documents without any entry in
   `ai_queries.cited_document_versions` in the last 30 days.
4. Analytics are computed on request (MVP volumes are small) with a 5-minute cache per
   `(workspace, period, tz)`; v1 adds nightly aggregates.

## Tests

Pest seeds fixed rows across a timezone boundary (Europe/Moscow and America/New_York) and asserts exact
numbers for every KPI, the grouping output and the empty state.
