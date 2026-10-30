# Question log — screen spec

Route `/assistant/history` · page `assistant/History` (foundation, extended) · ability
`assistant.history`; data scope from `ai_queries.view_all` / `view_department` / self (`scope` prop) ·
props contract §5, `AiQuery` §6.2 · privacy ADR-0015.

## Purpose
Let owners/admins (all) and managers (their departments) review what employees asked, what the bot
answered, which document versions it cited, and where it refused — with every view audited. Web
employees see only their own questions.

## Layout
- **Header**: H1 «Журнал вопросов»; scope chip («Все отделы» / «Ваши отделы» / «Только ваши вопросы»);
  privacy caption with `Eye` icon: «Просмотр журнала фиксируется в журнале аудита. Сотрудники
  уведомлены о хранении вопросов (180 дней).»
- **Stats strip**: total, answered, refused, escalated, average confidence.
- **Filters**: search, status (answered/refused), refusal reason, channel (Telegram/web), department.
- **Table**: time (local), employee (name; role at that time caption), departments at that time chips,
  question (2 lines), result badge (Ответ / Отказ + reason / Передан менеджеру), confidence (meter compact,
  band word), cited documents count, channel icon (`simple-icons` Telegram mark or `Monitor`). Row click →
  **detail drawer**.
- **Detail drawer**: question; answer (rendered, sanitized); citations with **document version and
  effective date at the time** (`cited_document_versions`) and a warning chip if that version is now
  archived; confidence meter; refusal reason; proposed actions with their status (proposed/executed →
  link to the incident/task); escalation link (`Q-15`); provider/model caption; request time and latency.
  Opening the drawer creates an `ai_query.viewed` audit entry.

## States
Empty: illustration (speech bubbles with a magnifier circle) + «Вопросов пока нет». Loading skeleton.
Error retry. Retention note at the bottom of the table: «Записи старше 180 дней удаляются автоматически».

## data-test
`history-scope`, `history-stats`, `history-filter-status`, `history-filter-reason`, `history-row`
(+ `data-query-id`, `data-refused`), `history-drawer`, `history-citation-version`, `history-action-status`.
