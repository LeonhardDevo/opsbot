# Worker

Node 24 · TypeScript (strict, ESM) · BullMQ 5 · grammY · Fastify 5 · pg · nodemailer · pino · prom-client.
Runs the outbox relay and all queues, delivers e-mail and Telegram notifications, hosts one Telegram bot per
company (plus the optional platform bot), drives document extraction and indexing, and fires the scheduled
ticks. Contracts: `PLATFORM.md` §4 and `docs/DOMAIN_CONTRACT.md` §3.2, §3.4, §8, §9.

## Run

```bash
docker compose up -d          # project root: Postgres 5442, Redis 6392, Mailpit 1032/8231
npm install
npm run migrate               # worker schema (also runs on boot)
npm run dev                   # uses .env, or .env.example when .env is missing (non-production)
npm test                      # vitest; Postgres/Redis suites skip with a notice when docker is down
npm run build && npm start
npm run lint && npm run typecheck
```

## Telegram

- **Bot registry** (`src/bot/botRegistry.ts`): one grammY bot per `bot_key` (`platform` or the decimal id of
  the company bot), built lazily from web's credentials endpoint, kept in memory only (LRU 500, idle eviction
  after 30 minutes, cluster-wide eviction through the `bot-registry` Redis channel). All bots share one
  handler pipeline; `ctx.botKey` identifies the bot.
- **Transports**: tokens matching `<digits>:TEST<30+ chars>` (or `TELEGRAM_DRIVER=fake`) use the fake
  transport: calls are captured and listed at `GET /dev/telegram/outbox?bot_key=`.
- **Ingress**: `POST /webhooks/telegram/{bot_key}` with the per-bot secret header (production) or one polling
  loop per bot under a Redis leader lock (`TELEGRAM_MODE=polling`). Updates go through the `telegram-updates`
  queue with job id `tg-update-{bot_key}-{update_id}`.
- **Connect**: web calls `POST /internal/v1/telegram/bots/{id}/verify` (signed, web only); the worker checks
  the token with `getMe`, registers the webhook or polling and the RU/EN command lists, and answers with the
  bot identity or an error code. Tokens never appear in job data, payloads or logs.
- **Commands** (private chats only): `/start [emp_<token>]`, `/help`, `/lang`, `/whoami`, `/history`, `/tasks`,
  `/incident` (category → description → confirmation), `/ask_manager`, `/queue` (managers; answers by reply),
  `/privacy`, `/unlink`. Until the employee notice is acknowledged only `/start /help /lang /privacy /unlink`
  work. In groups the bot posts one "write to me privately" notice per chat and stays silent.
- **Answers**: free text goes to ai `/v1/chat` with the user's departments, role and workspace settings; the
  query is logged to web, whose response provides the signed source links and the persisted action buttons.
- **Outbound limits**: `TelegramSender` takes tokens from Redis buckets per bot (25/s) and per chat (1/s);
  an empty bucket or a 429 moves the job to delayed without spending an attempt; 401 marks the bot revoked,
  403 marks the chat blocked.

## Queues

| Queue | Jobs |
|---|---|
| `emails` | `email.send` |
| `telegram` | `telegram.notify`, `opsbot.*` notification topics (templates in `src/bot/modules/opsbot/templates.ts`) |
| `documents` | `document.uploaded` → `document.extract` (ai extraction + OCR, pages cached 24 h) → `document.index` (ai indexing), `document.deleted`, `document.metadata_changed`, `workspace.reembed` (batched) |
| `telegram-updates` | `telegram.update` |
| `telegram-admin` | `telegram.bot.disconnect`, `telegram.context.invalidate` |
| `opsbot-schedules` | `opsbot.reminders.tick` (60 s), `opsbot.documents.tick` (hourly), `opsbot.limits.tick` (hourly), `opsbot.bots.reconcile` (03:00 UTC) |
| `stripe-events` | Stripe webhook forwarding |

## Dev endpoints (`DEV_ROUTES=1`, never in production)

```bash
curl -X POST http://127.0.0.1:8201/dev/telegram/update -H 'content-type: application/json' \
  -d '{"bot_key":"1","from":{"id":900000001,"language_code":"ru"},"text":"/start emp_<token>"}'
```

The body is `{bot_key?, update}`, a bare Telegram `Update`, or the shorthand
`{bot_key?, from, text?|callback_data?|chat_member_status?, chat_id?, reply_to_message_id?}`.

---

## Кратко по-русски

Воркер: ретранслятор outbox, очереди BullMQ, письма, уведомления в Telegram, отдельный бот для каждой
компании (реестр ботов с ленивой загрузкой токена из веба), привязка сотрудников по ссылке-приглашению,
уведомление о данных, сообщение об инцидентах, задачи, передача вопросов менеджеру и ответ менеджера через
«ответить», напоминания по расписанию, извлечение и индексация документов через сервис ИИ. Бот работает
только в личных чатах. Без ключей Telegram всё работает через тестовый транспорт.
