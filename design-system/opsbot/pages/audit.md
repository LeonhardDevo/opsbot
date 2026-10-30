# Audit log — screen spec

Route `/audit` · page `audit/Index` (foundation, restyled) · ability `audit.view` (owner, admin) · props
FOUNDATION_CONTRACT §3 (`logs`, `filters`, `actions`) · action catalog contract §14 · CSV export with plan
feature `audit_export` (pro, business).

## Purpose
Answer "who did what and when" for security-relevant and business actions: bot token changes, employee
links and offboarding, document approvals, incident/task changes, source-document views from Telegram,
question-log views.

## Layout
- **Header**: H1 «Журнал аудита»; action "Экспорт CSV" (secondary, `Download`; disabled with upgrade
  tooltip when the feature is missing).
- **Filters**: action (grouped select by domain: Бот, Сотрудники, Документы, Инциденты, Задачи, Вопросы,
  Настройки, Безопасность), actor (searchable), date range, source (web / bot / ai_proposal / system).
- **Timeline table**: time (local, mono seconds on hover), actor (name or «Система»; Telegram actions show
  `simple-icons` Telegram mark), action label (localized from `audit.actions.*`, never raw keys), subject
  (link when it still exists, e.g. «Инцидент № 12», «Регламент „Ключи и карты доступа“ v1.0»), source chip,
  IP (mono, `—` for Telegram), expand chevron.
- **Expanded row**: before/after diff as a two-column key/value table (removed values `--incident-coral-soft`
  background + `−` sign, added `--success-soft` + `+` sign — sign and colour, never colour alone);
  metadata JSON pretty-printed in mono for advanced fields (e.g. `chunk_id`, `version`).
Bot tokens, webhook secrets and phone numbers never appear in diffs (masked `…1234`).

## States
Empty (filters): «Ничего не найдено» + reset. Loading skeleton. Error retry.

## data-test
`audit-export`, `audit-filter-action`, `audit-filter-actor`, `audit-filter-source`, `audit-row`
(+ `data-action`), `audit-row-expand`, `audit-diff`.
