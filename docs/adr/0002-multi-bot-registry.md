# ADR-0002 — One Telegram bot per company: the multi-bot registry

- Status: accepted
- Date: 2026-10-22
- Overrides: PLATFORM.md §4.2 (`telegram.notify` payload), §4.3 (`/telegram/context`, `/telegram/link`),
  §4.7 (`/webhooks/telegram`, polling, dev route); FOUNDATION_CONTRACT §2 (`product.telegramBotUsername`)
- Related: ADR-0003 (token custody), ADR-0011 (private chats), ADR-0018 (rate limits),
  DOMAIN_CONTRACT §3.1 `telegram_bots`, §3.2, §9

## Context

Each customer connects **its own** bot from BotFather (`@CleanTeamHelpBot`) so employees talk to
"their company", not to a vendor. The foundation worker (`payflow-guard/apps/worker`) is single-bot
everywhere:

| Foundation assumption | Why it breaks with N bots |
|---|---|
| `worker.telegram_chats` PK = `telegram_user_id` | the same person can use two company bots (two employers) |
| cache key `account:{telegramUserId}` | the context differs per bot/workspace |
| `GET /internal/v1/telegram/context?telegram_user_id=` | ambiguous across bots |
| job id `tg-update-{update_id}` | `update_id` is unique **per bot**; two bots collide → updates silently deduped and lost |
| one webhook URL + one secret, one polling loop, one `setMyCommands` | per bot |
| `telegram.notify {user_id?\|chat_id?}` | does not say which bot sends; a private `chat_id` equals the user id for every bot |
| shared prop `product.telegramBotUsername` | one value for the whole product |

## Decision

### Bot identity
- `bot_key` is the stable routing key: the string `platform` (the optional vendor bot configured by
  `TELEGRAM_BOT_TOKEN`, used for trials/demo and as a notification fallback) or the decimal id of
  `public.telegram_bots.id`. Regex: `^(platform|[1-9][0-9]{0,18})$`.
- web owns `public.telegram_bots` (one row per workspace, `workspace_id` unique; columns in
  DOMAIN_CONTRACT §3.1). `telegram_accounts` gains `bot_key` and becomes unique on
  `(bot_key, telegram_user_id)` and `(bot_key, user_id)`.

### Worker changes (pinned so web/worker/e2e engineers patch the same way)
1. Migration `worker/0003_multi_bot`:
   ```sql
   ALTER TABLE worker.telegram_chats ADD COLUMN bot_key varchar(20) NOT NULL DEFAULT 'platform';
   ALTER TABLE worker.telegram_chats DROP CONSTRAINT telegram_chats_pkey;
   ALTER TABLE worker.telegram_chats ADD CONSTRAINT telegram_chats_pkey PRIMARY KEY (bot_key, telegram_user_id);
   ALTER TABLE worker.telegram_chats ALTER COLUMN bot_key DROP DEFAULT;
   ```
   Migration `worker/0004_group_notices` (ADR-0011).
2. Account cache key `account:{bot_key}:{telegram_user_id}`, TTL `TELEGRAM_CONTEXT_TTL_S=60`
   (negative results ≤ 15 s as in the foundation). Explicit invalidation via topic
   `telegram.context.invalidate` (ADR-0009).
3. Inbound job: queue `telegram-updates`, **job id `tg-update-{bot_key}-{update_id}`**, job data
   `{bot_key, update}` (never a token).
4. Webhook: `POST /webhooks/telegram/{bot_key}` with the per-bot secret header
   (`X-Telegram-Bot-Api-Secret-Token`, constant-time compare). Unknown, disconnected or not-yet-active
   bot → `404`; wrong secret → `401`. The foundation route `POST /webhooks/telegram` is removed; the
   platform bot uses `/webhooks/telegram/platform`.
5. Context lookup: `GET /internal/v1/telegram/context?bot_key=&telegram_user_id=` (inbound) or
   `?workspace_id=&user_id=` (outbound notifications). Both return the same `TelegramContext`
   (DOMAIN_CONTRACT §7.1).
6. `telegram.notify` gains a **required `workspace_id`** and an optional `bot_key`. Recipient resolution:
   - `user_id` → context `?workspace_id=&user_id=` → the workspace's company bot if it is `active` and
     the user is linked to it; otherwise the platform bot if the user linked it; otherwise
     `telegram_not_linked` (delivery callback → web may fall back to e-mail, ADR-0018).
   - `chat_id` requires `bot_key` (a chat id means nothing without its bot).
7. Dev route `POST /dev/telegram/update` accepts `{bot_key?, update}` or `{bot_key?, ...shorthand}`;
   `bot_key` defaults to `platform` so foundation tests keep passing. `GET /dev/telegram/outbox?bot_key=`
   filters captured calls.
8. **`BotRegistry`** (`apps/worker/src/bot/botRegistry.ts`) replaces the single `BotHandle`:
   - `get(botKey): Promise<BotEntry>` lazily builds one grammY `Bot` per key with `botInfo` from the
     credentials response (no `getMe` on every build) and installs the **shared** handler `Composer`
     (built once from the bot modules). `ctx.botKey` is available to every handler.
   - Platform entry comes from env; company entries from
     `GET /internal/v1/telegram/bots/{id}/credentials` (ADR-0003). Credentials live only in process
     memory.
   - LRU (max 500 entries), idle eviction after 30 min, `evict(botKey)` on a Redis pub/sub message
     on channel `{REDIS_PREFIX}bot-registry` (`{"type":"evict","bot_key":"42"}`) so every worker replica
     drops the entry when a bot is disconnected or its token rotated.
   - Transport per entry: `real` (Bot API) or `fake` (ADR-0003 `FakeTelegramDriver`), decided per token.
9. `setMyCommands` (ru + en) and `setWebhook` run **per bot** during verification (ADR-0003) and for the
   platform bot at startup. Polling is dev-only and per bot under a Redis leader lock (ADR-0018).
10. `allowed_updates = ["message", "callback_query", "my_chat_member"]` for every bot.

### Web changes
- Shared Inertia prop `product.telegramBotUsername` stays (platform bot), plus
  `workspace.bot: {key, username, status} | null` (DOMAIN_CONTRACT §6). Every deep link in the UI uses
  `workspace.bot.username` when the company bot is `active`, else the platform bot.
- Web users (owner/admin/manager) link their personal Telegram through the foundation flow
  (`/settings/integrations`), now issued for the workspace's active bot.

## Consequences

- Two companies' bots can deliver the same `update_id` without loss (covered by a Vitest).
- A person may be an employee of two companies: two `(bot_key, telegram_user_id)` rows, two contexts.
- With the platform bot, one Telegram user maps to exactly one workspace per bot (unique
  `(bot_key, telegram_user_id)`); linking a second workspace on the platform bot answers `409 conflict`
  with a localized "unlink first" message. Company bots never have this problem.
- Tests: Vitest for the job-id format, registry laziness/eviction, webhook 401/404 per bot, context cache
  key, notify resolution order; Pest for `telegram_accounts` uniqueness and context endpoint params.

## Alternatives rejected

- **One platform bot for everyone** — employees would see the vendor brand, and a Telegram user working
  for two companies would need a workspace switcher in chat. Kept only as fallback/demo.
- **A worker process per bot** — operationally heavy, does not scale to hundreds of small customers.
