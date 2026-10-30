# ADR-0015 — Question logging, who may read it, and the employee notice

- Status: accepted (amended 2026-10-22, plan review 2)
- Date: 2026-10-22
- Related: PLATFORM.md §10.1 (OpsBot employee notice), §10.2; ADR-0009, DOMAIN_CONTRACT §3.1
  `ai_queries`, §14, §15

## Context

"Фиксирует, кто и когда запросил информацию" collides with employee privacy. Access rules, what exactly
is logged and how employees are told were unspecified.

## Decision

### What is logged (`ai_queries`, foundation table extended)
`bot_key`, `role_at_time`, `department_ids_at_time int[]`, `cited_document_versions jsonb`
(`[{document_id, version, approval_status:'approved', effective_date}]`), `conversation_id`,
`refusal_reason`, `escalated_question_id`, `question_normalized` (lower + unaccent, generated column for
analytics, ADR-0016). Message content is stored only here and only for the retention period.

### Who may read it
| Viewer | Scope |
|---|---|
| owner, admin | all queries of the workspace (`ai_queries.view_all`) |
| manager | queries whose `department_ids_at_time` overlaps the manager's departments (`ai_queries.view_department`) |
| employee | only their own, last 10, via the bot `/history` (no web page unless `kind = web`, then `/assistant/history` filtered to self) |

Every view is audited: list page → one `ai_query.viewed` entry per page load with the filter as metadata;
detail drawer → one entry per opened query. The audit page itself is owner/admin only.

### Employee notice (first contact)
- Shown after `/start emp_…` (and to web users on first bot use): what is stored (questions, answers,
  time, department and role at that moment, cited documents), who can see it (owners/admins; managers of
  your departments), retention **per record type, rendered from `config/retention.php` + workspace
  overrides** (never a single number): questions and answers 180 days; a question passed to a manager is
  also kept in the questions queue until 365 days after it is answered or dismissed; incidents and tasks
  you report stay as company records until the company deletes its workspace or erases your data; the
  audit log of who opened which document is kept 365 days. It also says that answers are AI-generated
  with sources and can be wrong, that the admin can export or erase your data on request, and lists
  `/privacy`, `/history`, `/unlink`.
- Versioned Markdown `apps/web/resources/legal/{en,ru}/employee-notice.md`; the bot receives the
  rendered short version (≤ 1500 chars) from the context endpoint (`notice:{version, html}` when
  acknowledgement is pending).
- "I understand / Понятно" button → `POST /internal/v1/telegram/notice/ack {bot_key, telegram_user_id,
  version}` → `legal_acceptances(user_id, workspace_id, document = 'employee-notice', version,
  accepted_at, ip = null, user_agent = 'telegram', locale)`. Until acknowledged the bot answers
  questions only with the notice. A new notice version re-prompts once.
- `/unlink` removes the Telegram link (audit `telegram.unlinked`); it does not delete history (the
  employer's records), which `/privacy` explains together with how to request export or erasure via the
  admin.

### Employee data rights (plan review 2)
Telegram-only employees cannot reach Settings → Privacy, so the controller answers access and erasure
requests in-product (`employees.manage` within scope; owners/admins for any employee):
- **Export** `GET /employees/{user}/export` → queued job (foundation privacy export machinery, F2) → ZIP of
  JSON: profile (name, phone, position, departments, locale, status), Telegram link (username, linked_at;
  never tokens), `ai_queries` (question, answer, citations, time), incidents reported, tasks assigned,
  unanswered questions, `legal_acceptances`; signed download link valid 24 h, sent to the requesting
  admin (web notification + e-mail). Audit `employee.data_exported`.
- **Erase** `POST /employees/{user}/erase` — only for `offboarded` employees, requires password
  re-confirmation (foundation `password.confirm`), owner/admin only. In one transaction: delete
  `telegram_accounts`, `employee_links`, phone, position; `name` → «Бывший сотрудник №<id>» / "Former
  employee #<id>" (localized at render time from a flag, stored as `erased_at`); `ai_queries.question` and
  `answer` set to null (counts for analytics stay); `unanswered_questions.question` → `[erased]`;
  incidents and tasks stay as business records with the reporter shown pseudonymized; incident
  descriptions are kept (business record; the dialog says so). Outbox `telegram.context.invalidate`.
  Audit `employee.erased` (metadata: counts, no personal data); earlier audit entries keep the user id
  only. Erasure is irreversible; the employee cannot be reactivated afterwards.
- Pest: export contents and no secrets, erase only when offboarded, re-auth required, scope denial,
  pseudonymization in incident/task views, analytics counts unchanged after erase.
## Consequences

- Pest: manager scope (in/out of department), employee web history is self-only, audit entries per view,
  notice ack recorded once per version, bot refuses to answer before ack (Vitest via dev route).
