# ADR-0012 — Plans priced by seats and documents; over-limit and past-due behaviour

- Status: accepted (amended 2026-10-22, plan review 2)
- Date: 2026-10-22
- Overrides: FOUNDATION_CONTRACT `Plan.price_monthly/price_yearly` (USD floats) → integer minor units;
  foundation `config/billing.php` values and the advertised `sso` feature
- Related: ADR-0004 (seats SQL), DOMAIN_CONTRACT §11

## Context

The idea prices by number of users and documents through Stripe Billing. The foundation has four flat
USD plans, advertises an `sso` feature that does not exist, and defines nothing for over-limit,
downgrade, `telegram_only` seats or `past_due`. Stripe does not serve Russian merchants while `ru` is a
primary locale.

## Decision

1. Plan keys stay `free | starter | pro | business`. Prices are stored as **integer minor units per ISO
   currency** (`prices: {USD|EUR|RUB: {monthly_minor, yearly_minor}}` in `config/billing.php`, values in
   DOMAIN_CONTRACT §11). Yearly = 10 × monthly. The workspace's currency and gateway come from its
   market (PLATFORM §11.1): onboarding asks for the country → `workspaces.country`, `workspaces.currency`
   → gateway `yookassa` for RU/RUB, `stripe` otherwise, `fake` when no keys (dev, CI, E2E default).
2. Limit keys (`config/billing.php → plans.<plan>.limits`), `null` = unlimited:
   `seats`, `documents`, `storage_mb`, `ai_queries_monthly`, `max_pages_per_document`,
   `max_file_mb`, `bots` (always 1 — one company bot per workspace). Values in DOMAIN_CONTRACT §11.
3. Counting: `seats` = ADR-0004 SQL; `documents` = documents not archived; `storage_mb` = sum of
   `size_bytes` of non-deleted documents; `ai_queries_monthly` = `ai_queries` rows in the current calendar
   month **in the workspace timezone** (refusals count; web preview counts; rows with
   `refusal_reason = 'limit_reached'` do not count — no AI call was made).
4. **Over limit** (≥ 100 %): block only *creation* — uploads, employee creation, link issuing to new
   employees, e-mail invitations (HTTP 402-style redirect back + upgrade dialog). Existing documents stay
   answerable, existing employees keep working, incidents/tasks are never blocked. At 80 % a warning
   banner. A localized admin notice (Telegram if linked, else e-mail) at most **once per day per limit
   key** (`limit_notices(workspace_id, limit_key, notified_on)`).
   - `ai_queries_monthly` exhausted: the bot answers employees with `limits.ai_exhausted` ("Question
     limit reached for this month — your question can be passed to a manager") and an `escalate` button;
     no ai call is made. The worker logs the question via `POST /internal/v1/ai-queries` with
     `refused = true`, `refusal_reason = 'limit_reached'` and one action
     `{type:'escalate', payload:{reason:'limit_reached'}}`, so the button references a stored query.
     In `paused` state free text is not logged; the reply carries a "Report incident" button (§6).
5. **Downgrade** keeps all data; creation stays blocked until usage is under the new limits.
6. **Past due**: `subscription_state` is derived — `ok`; `grace` while `past_due` for ≤ 7 days since
   `past_due_since`; `paused` after that. **`paused` stops only AI answers** (amended in plan review 2:
   safety and operations features are never gated, DOMAIN_CONTRACT §11):
   - free text → `subscription.paused` ("AI answers are paused for your company — contact your
     administrator. You can still report an incident.") + a "Report incident" button; no ai call, nothing
     logged; the web assistant preview is disabled with the same text;
   - still working: `/incident` (flow), `/tasks`, `/help /privacy /unlink /lang /whoami /history`,
     callbacks `task_start task_done incident_take incident_resolve q_answer q_dismiss` and the
     `flow_incident_*` steps, all `opsbot.incident.*` / `opsbot.task.*` notification topics, task
     reminders and the `(NULL, injury) → owner` routing; escalation of existing questions and manager
     answers keep working;
   - not available: AI answers, `/ask_manager` for new questions, creation limited by §4 as usual;
   - web shows a blocking banner for owners/admins with the billing link (other web users see a
     read-only notice; incidents and tasks pages stay usable). Back to `active` → `ok` immediately.
7. **No SSO claim**: the `sso` feature key is removed from all plans and copy.
8. **Markets and gateways** (replaces "RUB not supported", which contradicted PLATFORM §11): the
   `BillingGateway` interface and its `stripe` (primary, fully implemented — Checkout, Customer Portal,
   webhooks via worker ingress, tested against `stripe-mock` on port 12121), `yookassa` (RU readiness:
   first payment with `save_payment_method`, monthly server-side charge by the worker scheduler, 54-ФЗ
   receipts, IP-allow-listed + re-fetched notifications) and `fake` drivers are **foundation step F3**;
   OpsBot only configures them (prices per currency, plan lookup keys) and must not contradict §11.
   Pricing pages show the visitor's market currency (`Intl.NumberFormat`), never a "not supported" note.
   The dunning state machine above (`grace`, `paused`) is the same for both gateways.

## Consequences

- Pest: seats SQL fixture; each limit blocks creation at 100 % and not other actions; once-a-day notice
  dedupe with a fixed clock; `grace → paused` boundary at exactly 7 days; paused bot text via dev route
  (Vitest/E2E); in `paused`: `/incident` flow, `task_done` callback and `opsbot.incident.created`
  notification still work (Vitest), and the E2E limits spec reports an incident from a paused workspace;
  price resolution per currency (USD/EUR/RUB) and market → gateway mapping (Pest).
