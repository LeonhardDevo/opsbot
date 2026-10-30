# ADR-0004 — Employees who exist only in Telegram

- Status: accepted (amended 2026-10-22, plan review 2)
- Date: 2026-10-22
- Overrides: FOUNDATION_CONTRACT §3 (roles `owner|admin|member`), foundation `users.email NOT NULL`
- Related: ADR-0002, ADR-0009, ADR-0012 (seats), ADR-0015 (privacy notice), DOMAIN_CONTRACT §2, §3.1, §4.6

## Context

The foundation links Telegram only for existing web users (e-mail invitation → password →
`/settings/integrations` deep link). Cleaners and field staff usually have no corporate e-mail and
will never log into a SaaS. They must still be counted as seats, filtered by department and role, and
offboarded cleanly.

## Decision

### Model
- `users.email` and `users.password` become **nullable** (Postgres unique index on `email` allows many
  NULLs). Fortify login requires e-mail + password, so an e-mail-less user can never log in.
- `workspace_user` gains:
  - `kind` enum `web | telegram_only` — `telegram_only` users have no web login;
  - `status` enum `invited | active | offboarded`;
  - `phone` (encrypted, optional, E.164), `position` (optional free text), `offboarded_at`,
    `offboarded_by`.
- Roles enum (replaces foundation `member`): `owner | admin | manager | employee`. Owners, admins and
  managers must be `kind = web`. Employees default to `telegram_only`; an employee may be `web` when
  invited by e-mail (office staff) and then gets the web assistant + their own question history only.
- A `telegram_only` user belongs to exactly one workspace (created inside it; enforced by
  `EmployeeService`, tested).
- Departments: `department_user(department_id, user_id, is_primary)`; exactly one primary per user per
  workspace when the user has any department (ADR-0009).

### Onboarding an employee
1. Manager/admin creates the employee: `POST /employees {name, department_ids[], primary_department_id,
   role:'employee', phone?, position?, locale}` → `users` row (no e-mail), `workspace_user`
   (`kind=telegram_only`, `status=invited`), `department_user` rows. Requires a free seat (ADR-0012).
2. System issues a one-time link: `employee_links` row (`token_hash` = sha256 of a 40-char base62
   token, `expires_at = now + 7 days`, `bot_key` of the active bot at issue time). Shown once as
   `https://t.me/<bot_username>?start=emp_<token>` + QR code (inline SVG generated client-side with the
   `qrcode` npm package — no external QR service) + copy button. Issuing a new link revokes older
   unused links of that employee.
3. Employee opens the link → bot `/start emp_<token>` → worker `POST /internal/v1/telegram/link
   {bot_key, token:'emp_<token>', telegram_user_id, chat_id, username, language_code}`.
4. Web validates: hash match, unused, not revoked, not expired, **`employee_links.bot_key` equals the
   calling `bot_key`** (a link of workspace A cannot be redeemed on another company's bot); the
   `(bot_key, telegram_user_id)` pair is not linked to another user (`409 conflict`). Then, in one
   transaction: create `telegram_accounts`, set `workspace_user.status = active`, mark the link used,
   audit `employee.linked`. Response includes `notice_required: true`.
5. The bot shows the employee notice (ADR-0015) with an "I understand / Понятно" button; questions are
   answered only after acknowledgement.

Web users (owner/admin/manager, web employees) keep the foundation flow: one-time 15-minute token from
`/settings/integrations`, redeemed on the workspace's active bot.

### Offboarding
`DELETE /employees/{user}` (confirm dialog, `employees.manage`, managers only within their departments):
1. `workspace_user.status = offboarded`, `offboarded_at/by`; open tasks assigned to the user are
   reassigned to the department manager (fallback: workspace owner) with an `incident_events`/task note.
2. Outbox `telegram.notify {workspace_id, bot_key, chat_id, template:'opsbot.access_revoked'}` (sent
   before unlinking, explicit bot+chat because the context will be gone), then `telegram_accounts` row
   deleted, unused links revoked, outbox `telegram.context.invalidate`.
3. Audit `employee.offboarded`. The user row is kept (history, audit, ai_queries attribution); a
   re-hire creates a fresh `workspace_user` activation via a new link.

### Seats (exact SQL, used by `PlanLimits` key `seats`)
```sql
SELECT
  (SELECT count(*) FROM workspace_user wu
     WHERE wu.workspace_id = :workspace_id AND wu.status IN ('invited', 'active'))
+ (SELECT count(*) FROM workspace_invitations wi
     WHERE wi.workspace_id = :workspace_id AND wi.accepted_at IS NULL AND wi.expires_at > now())
AS seats_used;
```
Invited-but-not-linked employees and pending e-mail invitations count (otherwise the limit could be
bypassed by never redeeming links). Offboarded users do not count. Pest test with a fixed dataset.

## Consequences

- Foundation code assuming `email` is a string (Member type, mails, avatars) must handle `null`:
  `Member.email: string | null`; notification e-mail fallback skips users without e-mail.
- Data-subject rights (PLATFORM §10.2) for a `telegram_only` user are exercised **by the controller (the
  customer) in-product**, because the employee cannot log in: `GET /employees/{user}/export` and
  `POST /employees/{user}/erase` (ADR-0015 "Employee data rights", DOMAIN_CONTRACT §5). The employee
  requests either via `/privacy`, which says the admin can export or erase their data on request.

## Alternatives rejected

- **Separate `employees` table mapped to users** — duplicates identity, roles and department logic;
  every query would need a union. One `users` table with `kind` is simpler.
- **Self-signup in the bot with a company code** — no control over who joins; seats and ACL leak.
