# Incidents — screen spec

Routes `/incidents` (`incidents/Index`), `/incidents/{incident}` (`incidents/Show`) · abilities
`incidents.view_*`, `incidents.create`, `incidents.manage` · props contract §5, `Incident`,
`IncidentEvent` §6.2 · state machine contract §4.2.

## Purpose
See every reported problem (damage, complaint, no access, injury, lost item, other), who owns it and how
long it has been open; move it through open → in progress → resolved → closed.

## Index layout
- **Header**: H1 «Инциденты»; view toggle (segmented: "Доска" `SquareKanban` / "Таблица" `Table`);
  primary "Новый инцидент" (`Plus`).
- **Filters** (one row, wraps): search, status (table view), category multi-select (with icons),
  severity, department, assignee ("Мои" shortcut chip), date range.
- **Board** (default ≥ 1024): 4 columns open / in_progress / resolved / closed, header with status badge
  and count from `counts`; closed column collapsed by default. **Card**: number (mono `№ 12`), category
  icon + label, severity badge, title (2 lines max), department chip, reporter, assignee avatar or
  «Не назначен» (sun), age («3 ч», coral when high severity and > 2 h without "in progress"), source:
  `Bot` icon for incidents from Telegram, plus the caption «по подсказке ассистента» when
  `source = 'ai_proposal'`. Drag between columns only along allowed transitions; the menu offers the same moves for
  keyboard users; disallowed drops snap back with a toast.
- **Table** (default < 1024 and selectable): number, title, category, severity, status, department,
  assignee, reporter, created, age; sortable; mobile cards.

## Create dialog
Category (radio cards with icons), severity (segmented, default by category), description (textarea,
2000), client reference (optional), department (default: my primary), assignee (optional, default by
routing — shows the resolved name «Будет назначено: Олег Петров» from `GET /routing/preview`, refreshed
when category or department changes).

## Show layout
- Header: `№ 12` (mono) + title (H1), badges (category, severity, status), actions: allowed transitions as
  buttons (e.g. "Взять в работу", "Решено", "Закрыть", "Переоткрыть"), assignee select, edit severity/category
  (menu). Closing/reopening asks for a comment.
- Left (8 cols): description card; **Timeline** (`incident_events`, newest last): created, assigned,
  status changes, comments (bubble style, `--surface-2`), task links; comment composer at the bottom.
- Right (4 cols): details list (reporter, department, client ref, created/resolved/closed times in
  workspace tz, source); **Source question** card when `sourceQuery` (question, answer excerpt, citation
  chips — scoped view, audited); **Tasks** card (linked tasks with status/due + "Создать задачу" sheet).

## States
Empty board: illustration (calm board with three empty columns and a check) + «Инцидентов нет — сотрудники
могут сообщить о проблеме из Telegram командой /incident». Loading: column skeletons. Error: card with retry.

## data-test
`incidents-view-toggle`, `incidents-new`, `incident-form-category`, `incident-form-severity`,
`incident-form-description`, `incident-form-submit`, `incidents-column` (+ `data-status`),
`incident-card` (+ `data-number`, `data-severity`, `data-status`), `incident-transition`
(+ `data-to`), `incident-assignee`, `incident-comment-input`, `incident-comment-submit`,
`incident-timeline`, `incident-create-task`, `incident-source-query`.
