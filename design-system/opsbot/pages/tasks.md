# Tasks — screen spec

Route `/tasks` · page `tasks/Index` · abilities `tasks.view_*`, `tasks.create`, `tasks.manage` · props
contract §5, `Task` §6.2 · state machine §4.3 · reminders ADR-0013.

## Purpose
Track follow-up work (often created from incidents or proposed by employees in the bot): who does what by
when, with reminders that respect shift hours.

## Layout
- **Header**: H1 «Задачи»; view toggle (Доска / Таблица); primary "Новая задача" (managers+).
- **Quick filter chips**: «Мои», «Просроченные» (count, coral), «Сегодня», «На неделе»; plus filters:
  status, assignee, department, search.
- **Board**: columns todo / in_progress / done / cancelled (cancelled collapsed). **Card**: `T-34` (mono),
  title, due chip (MASTER: overdue coral with «просрочено на 5 ч»), assignee, department chip, linked
  incident chip (`№ 12`), source caption (from Telegram / from incident), quick action "Готово" for the
  assignee. Same keyboard alternative and transition rules as incidents.
- **Table**: number, title, status, due (local), assignee, department, incident, created by, source.

## Create / edit sheet
Title (160), description, due date + time picker (workspace timezone shown as caption «Время: Москва,
UTC+3»), assignee (searchable; employees included), department, linked incident. Under the due field a
static rule hint: «Напомним за 2 часа, в срок и каждые 24 часа после (до 3 раз), не ночью». After saving,
the task drawer shows the **server-planned** reminders from `task.reminders` (e.g. «22 окт. 12:30 — за 2
часа · 22 окт. 14:30 — в срок · 23 окт. 14:30 — просрочено (1)»); reminders moved by quiet hours carry a
`Moon` icon and the caption «перенесено на 08:00». Note for web users: «Напоминания приходят в Telegram;
если он не подключён — на почту».

## Task detail (drawer from card/row)
Fields, status buttons per allowed transitions, reminders table (kind, planned, status), history.

## States
Empty: illustration (checklist sheet + alarm clock circles) + «Задач пока нет» + create (managers) or
«Задачи появятся, когда менеджер их назначит» (employees). Loading skeleton. Error retry.

## data-test
`tasks-view-toggle`, `tasks-new`, `tasks-quick-filter` (+ `data-filter`), `task-card`
(+ `data-number`, `data-status`, `data-overdue`), `task-done`, `task-form-title`, `task-form-due`,
`task-form-assignee`, `task-form-submit`, `task-reminders`, `task-drawer`, `task-transition`
(+ `data-to`).
