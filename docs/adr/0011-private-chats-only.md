# ADR-0011 — The bot answers only in private chats

- Status: accepted
- Date: 2026-10-22
- Related: ADR-0002, ADR-0009, DOMAIN_CONTRACT §3.2 `telegram_group_notices`, §9.4

## Context

If a company bot is added to a team group, an answer produced for one employee's role and departments
becomes visible to every member, bypassing the ACL. The foundation ignores free text outside private
chats, but commands and callbacks are not fully guarded and nothing tells the team what to do.

## Decision

1. Only `chat.type === 'private'` is served: commands, free text, callbacks, flows.
2. In `group | supergroup`:
   - On `my_chat_member` (bot added) **or** the first message addressed to the bot (mention, reply to
     the bot, or a `/command@bot`), the bot replies **once per chat** with the localized
     `group.private_only` text and a URL button "Write to me privately" → `https://t.me/<bot_username>`.
   - The notice is recorded in `worker.telegram_group_notices(bot_key, chat_id, notified_at)` (PK
     `(bot_key, chat_id)`); every later update from that chat is ignored silently.
   - Callback queries from non-private chats are answered with `actions.expired` and nothing else.
3. Channels: `channel_post` is not in `allowed_updates`.
4. Bot settings page tells admins to disable groups in BotFather (`/setjoingroups → Disable`) and shows
   whether `can_join_groups` is true (from `getMe`).

## Tests

Vitest through the dev transformer: group message → exactly one notice; second message → no outbound
calls; `my_chat_member` → notice; group callback → only `answerCallbackQuery`; private chat unaffected.
