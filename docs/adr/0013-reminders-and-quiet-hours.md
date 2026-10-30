# ADR-0013 — Task reminders: planned in web, dispatched by a worker tick, quiet hours respected

- Status: accepted
- Date: 2026-10-22
- Related: ADR-0018, DOMAIN_CONTRACT §3.1 `task_reminders`, §4.3, §12

## Context

"Напоминания о незакрытых задачах" had no schedule, timezone, quiet hours, idempotency or stop
condition. Field staff on shifts must not be pinged at night; duplicate scheduler ticks must not
double-send.

## Decision

1. `tasks.due_at` is `timestamptz` (UTC); UI renders in the workspace timezone (`workspaces.timezone`).
2. **Plan** (pure function `TaskReminderPlanner::plan(Task, CarbonTimeZone, QuietHours, now)`):

   | kind | planned at |
   |---|---|
   | `pre_due` | `due_at − 2h` (skipped if already past at creation) |
   | `due` | `due_at` |
   | `overdue_1..3` | `due_at + 24h × n` |
   | `escalation` | `overdue_3` instant + 1 min (dispatched after `overdue_3`): notify the department manager (fallback: owner) once |

   Every planned instant falling inside quiet hours (default 21:00–08:00 workspace local time,
   configurable in `ops_settings`) is shifted to the next quiet-hours end (08:00 local). Wall-clock
   arithmetic is done in the workspace zone, then converted to UTC (DST-safe).
3. Rows `task_reminders(task_id, kind, planned_at, status, sent_at, outbox_id)` with **unique
   `(task_id, kind)`** are (re)planned in the same transaction as task create / `due_at` change /
   assignee change (unsent rows cancelled and re-inserted).
4. **Dispatch**: worker repeatable job `opsbot.reminders.tick` every 60 s → web
   `POST /internal/v1/opsbot/reminders/dispatch` → web selects `status = planned AND planned_at <= now()`
   `FOR UPDATE SKIP LOCKED LIMIT 200`, re-reads each task: `done | cancelled` or no assignee →
   `skipped`; otherwise publishes `opsbot.task.reminder_due` (or `opsbot.task.escalated`) and sets
   `sent`, `sent_at`, `outbox_id` in the same transaction. Overlapping ticks cannot double-send; outbox job
   id = outbox id gives worker-side dedupe.
5. Telegram buttons on reminders: "Done" (`task_done:{id}`), "Open" (URL, web users only).
6. Reminders are not sent while the subscription is `paused` (rows are `skipped` with reason).

## Decision note

The review suggested job ids `task:{id}:reminder:{n}` on a domain topic consumed by the worker that
re-reads the task. We moved the re-read into web (where the business rules live) and made
`(task_id, kind)` the uniqueness key; the effect — at most one send per reminder kind, no-op on done or
cancelled tasks — is the same, with one fewer network hop.

## Tests

Pest with `Carbon::setTestNow`: plans for Europe/Moscow (no DST) and America/New_York across
2026-03-08 and 2026-11-01; quiet-hours shift at 20:59/21:00/07:59/08:00 boundaries; re-plan on due change;
double dispatch call sends once; done task → skipped; escalation after overdue_3 only.
