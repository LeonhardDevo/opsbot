# ADR-0018 — Notification topics, e-mail fallback, per-bot rate limits, polling leader lock

- Status: accepted
- Date: 2026-10-22
- Overrides: PLATFORM.md §4.7 (one polling loop, one global Telegram limiter)
- Related: ADR-0002, ADR-0003, ADR-0013, ADR-0014, DOMAIN_CONTRACT §8, §9.2

## Context

With N company bots: polling means N long-poll loops; two worker replicas polling the same token get
`409 Conflict: terminated by other getUpdates request`; Telegram limits (≈30 msg/s per bot, ≈1 msg/s per
chat) apply per bot, while the foundation has one global limiter on the `telegram` queue. Domain events
(incident created, task assigned, question escalated…) need a pinned delivery path.

## Decision

### Domain notification topics
- Web publishes **one outbox message per recipient** for domain notifications, with a pinned zod/PHP
  schema per topic (DOMAIN_CONTRACT §8): `opsbot.incident.created`, `opsbot.incident.status_changed`,
  `opsbot.task.assigned`, `opsbot.task.reminder_due`, `opsbot.task.escalated`,
  `opsbot.question.escalated`, `opsbot.question.answered`, `opsbot.document.review_due`,
  `opsbot.limits.notice`, `opsbot.bot.revoked`.
- All route to queue `telegram`; handler `createOpsbotNotifyHandler(topic)` validates the topic schema,
  renders the bot-module template of the same name in the recipient's locale, resolves the bot
  (ADR-0002 rule) and sends, then reports `POST /internal/v1/deliveries`.
- **E-mail fallback**: if the delivery report is `failed` with `error = telegram_not_linked` (or
  `chat_blocked`) and the recipient is a web user with an e-mail, web publishes `email.send` with the
  same content rendered by Laravel (localized mail templates `resources/views/mail/opsbot/*`). Employees
  without Telegram are shown as "not reachable" in the UI instead.

### Per-bot outbound limits
- BullMQ OSS has no per-group rate limiting (groups are a BullMQ Pro feature), so the foundation's
  queue-wide limiter is removed and replaced by Redis token buckets inside `TelegramSender`:
  `tg-rate:bot:{bot_key}` 25 msg/s and `tg-rate:chat:{bot_key}:{chat_id}` 1 msg/s.
  When a bucket is empty the job is moved to delayed (`job.moveToDelayed(now + wait)` + `DelayedError`)
  — no attempt consumed. A `429` honours `parameters.retry_after` the same way.
- `401` from Telegram for a company bot → report `revoked` (ADR-0003) and fail the job permanently;
  `403 bot was blocked by the user` → `POST /internal/v1/telegram/chats/blocked {bot_key,
  telegram_user_id, blocked:true}` (web sets `telegram_accounts.blocked_at`, UI shows "Blocked the bot")
  and the delivery is `failed` with `chat_blocked`.

### Polling (dev only) and webhooks (production)
- Production: `TELEGRAM_MODE=webhook`, one route per bot (`/webhooks/telegram/{bot_key}`).
- Dev: `TELEGRAM_MODE=polling` — one loop per active bot, each under a Redis leader lock
  `poll-lock:{bot_key}` (`SET NX PX 30000`, renewed every 10 s by the holder; a replica that fails to
  renew stops its loop). On startup the worker lists bots via `GET /internal/v1/telegram/bots/active`.
- `TELEGRAM_MODE=disabled` or fake transport: no loops; the dev route still works.

### Scheduled ticks (BullMQ repeatable jobs, queue `opsbot-schedules`, fixed job ids)
`opsbot.reminders.tick` (60 s), `opsbot.documents.tick` (hourly: review-due notices, superseded archive),
`opsbot.limits.tick` (hourly: once-a-day notices at ≥ 09:00 workspace time),
`opsbot.bots.reconcile` (daily: finalize stuck `disconnecting` bots), F2 `privacy.purge.due` (daily).

## Decision note

The review asked for "BullMQ limiter grouped by bot_key"; that requires BullMQ Pro. The token-bucket +
`moveToDelayed` approach gives the same per-bot fairness with OSS BullMQ.

## Tests

Vitest: bucket exhaustion delays (fake timers), 429 retry_after, 401 → status report, 403 → blocked
report, leader lock (two runtimes, one loop), per-topic schema rejection, notify resolution order.
