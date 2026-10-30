# Dashboard — screen spec

Route `/dashboard` · page `Dashboard` · any member (data scoped: owner/admin all, manager departments,
web employee own) · props `DashboardProps` (contract §6.2).

## Purpose
Operational overview for the day: what needs attention now (incidents, overdue tasks, unanswered
questions, documents due for review, bot health) and how the assistant is doing.

## Layout ("Today" feed inside `OpsShell`, MASTER §4.1 — no KPI tile row, no card grid)
Two columns inside the content sheet at ≥ 1024: **feed** (8 cols, max 760 px reading width) and
**side stack** (4 cols). At ≥ 1440 the Live desk column is on the right of the sheet; the dashboard then
hides desk bubbles that already appear in its feed (no duplicates).
- **Header**: Display M greeting «Доброе утро, Марина» (time-of-day in workspace tz) + date caption;
  actions: "Создать инцидент" (secondary), "Задать вопрос боту" (primary → `/assistant`).
- **Pulse line** (one sentence of text, not tiles): «Сегодня: 3 открытых инцидента, 2 просроченные
  задачи, 4 вопроса ждут ответа · бот ответил на 86 % вопросов за 7 дней» — numbers are links to the
  filtered lists, `tabular-nums`, coral/sun text colour + icon when > 0 (from `kpis`).
- **Onboarding card** (only when `onboarding` not null): checklist of remaining steps with links; dismiss
  hides it until a step changes.
- **Banners** (stacked, above the feed): subscription `grace`/`paused` (coral; paused text says AI answers
  are paused while incidents and tasks keep working), bot `revoked`/`invalid_token` (coral, link to
  `/bot`), limits ≥ 80 % from `limitsWarning` (sun, owners/admins, opens the upgrade dialog).
- **Feed** (`today`, urgency order from the contract): conversation rows grouped under day-part captions
  ("Требует внимания", "Сегодня", "Документы"). Each row = `ChatBubble`-styled card: left icon tile
  (category / `MessageCircleQuestionMark` / `AlarmClock` / `CalendarClock` / `Bot`), title, one-line excerpt,
  actor + relative time (mono id `№ 12`, `Q-15`, `T-34`), quick actions on the right («Взять», «Ответить»
  opens the answer sheet inline, «Готово»). High severity rows have a 3 px coral left edge (+ icon + text).
  "Показать ещё" loads the rest via `/desk/feed`.
- **Side stack**: (1) bot card (status pill, `@username`, linked employees, last seen, "Отправить тестовое
  сообщение"); (2) "Инциденты по важности" labelled list (high / medium / low with open count and
  «без ответственного: N»), rows link to the filtered board; (3) "Вопросы за 14 дней" compact stacked bar
  chart (fixed mapping `--chart-1/2/3`, legend + table toggle); (4) my tasks (≤ 5, "Done" quick action).
Employees with web access see only their own feed items (their tasks, their incidents, answers to their
questions) and no side-stack charts.

## States
Loading: 6 bubble skeletons + side-card skeletons. Empty feed: illustration (calm chat: two bubbles with a
check mark) + «Всё спокойно — новых инцидентов и вопросов нет» + secondary "Пригласить сотрудников" when
no employees are linked. Chart empty: "Пока нет вопросов — пригласите сотрудников". Error per block with
retry (feed and side cards load independently via deferred props).

## Interactions & a11y
The feed is a `role="feed"` list with `aria-busy` while loading; each row is an `article` with the title
as its label; quick actions are buttons with `aria-label`s including the item title. Chart has a table
alternative. Rail and tab-bar badges mirror `badges`.

## Responsive
≥ 1024: feed + side stack; 768: single column, side stack below the feed (bot card first); 375: single
column, pulse line wraps, quick actions collapse to max 2 inline buttons
+ overflow menu (`Ellipsis`).

## data-test
`dashboard-pulse` (+ `data-kpi` on each number), `dashboard-feed`, `dashboard-feed-item` (+ `data-kind`,
`data-id`), `dashboard-feed-action` (+ `data-action`), `dashboard-feed-more`, `dashboard-questions-chart`,
`dashboard-chart-table-toggle`, `dashboard-severity`, `dashboard-my-tasks`, `dashboard-task-done`,
`dashboard-bot-card`, `dashboard-onboarding`, `dashboard-banner` (+ `data-banner`).
