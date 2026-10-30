# Bot settings — screen spec

Route `/bot` · page `bot/Index` · ability `bot.manage` · props contract §5 (`bot: TelegramBot|null`,
`platformBotUsername`, `commands`, `telegramMode`, `driver`); actions POST/PATCH/DELETE `/bot`,
POST `/bot/test-message`.

## Purpose
Connect the company's own Telegram bot, see its health, rotate the token, disconnect safely, and preview
what employees see.

## Layout (8 + 4 columns at ≥ 1024; single column below)
**Main column**
1. **Status card** — `BotStatusPill` (aria-live), `@username` (mono) with "Открыть в Telegram" link,
   first name, mode (webhook/polling/fake caption), last seen (relative), linked employees count,
   `pending_relink_count` warning (sun) with link to employees filtered `needs_relink`.
2. **Connect card** (when `bot` is null, `invalid_token`, `revoked` or `disconnected`):
   BotFather steps (numbered list, 4 items) + token input (password type, show/hide, mono,
   `autocomplete="off"`, `spellcheck="false"`) + "Подключить" primary. Inline result/errors from
   `bot.errors.*` with hints: invalid_token «Токен неверный — скопируйте его из BotFather ещё раз»,
   already_connected «Этот бот уже подключён к другому пространству OpsBot», network «Telegram не ответил —
   попробуйте ещё раз», webhook_failed «Проверьте публичный HTTPS-адрес воркера», token_mismatch.
   Dev hint when `driver = fake`.
3. **Token rotation** (when active): explanation «Если вы отозвали токен в BotFather, вставьте новый»
   + token input + "Обновить токен" (secondary). Different-bot warning dialog: «Это другой бот —
   сотрудникам понадобятся новые ссылки» with the affected count.
4. **Test** — "Отправить тестовое сообщение мне" (requires my Telegram link; otherwise a link to
   `/settings/integrations`).
5. **Group chats policy** — info callout: bot answers only in private chats; recommend BotFather
   `/setjoingroups → Disable`; shows `can_join_groups` state (`ShieldCheck` success when false, sun when true).
6. **Danger zone** card (`--incident-coral-soft` border) — "Отключить бота" destructive; confirm dialog
   types the bot username to confirm; explains that employee links stop working and webhooks are removed.
   While `disconnecting`: pill + caption «Отключаем…».

**Side column**
- `TelegramPreview` showing the `/start` greeting and a sample answer in the selected locale.
- **Commands** list (`/command` mono + description) as registered via `setMyCommands`.
- Platform bot info card when no company bot: «Пока используется общий бот @…» (if configured).

## States
Loading: skeleton status card. Error loading: error card with retry. Connecting: button spinner + aria-live
«Проверяем токен…» (≤ 15 s), then result.

## data-test
`bot-status`, `bot-username`, `bot-token-input`, `bot-connect`, `bot-error` (+ `data-code`),
`bot-rotate-input`, `bot-rotate`, `bot-test-message`, `bot-disconnect`, `bot-disconnect-confirm`,
`bot-commands`, `bot-preview`, `bot-groups-policy`.
