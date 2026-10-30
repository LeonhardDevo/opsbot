# Billing & plan usage — screen spec

Route `/settings/billing` · page `settings/Billing` (OpsBot page on the foundation route) · ability
`billing.view` (actions need `billing.manage`, owner only by default) · props `BillingProps` (contract §6.2)
· layout `OpsShell` → settings section.

## Purpose
Show what the company pays for and how close it is to each limit — "plans by seats and documents" is the
product's core SaaS promise — and make upgrading, paying and cancelling one click away. Honest about
what happens at limits and when payment fails.

## Layout (feed width 760 px + side card at ≥ 1024)
1. **Header**: H1 «Тариф и оплата» / "Plan & billing"; caption with the gateway and currency
   («Оплата через Stripe · USD» / «Оплата через ЮKassa · ₽»; `fake`: «Тестовый режим оплаты»).
2. **State banner** (only when not `ok`/`active`):
   - `trialing`: mint (`--accent-mint-soft`) info card «Пробный период Pro — осталось 9 дней» with countdown (`useCountdown`, days
     then hours in the last 24 h) + "Выбрать тариф".
   - `grace` (past due ≤ 7 days): `--highlight-sun-soft` card «Платёж не прошёл. Через 3 дня ответы ИИ будут приостановлены;
     инциденты и задачи продолжат работать» + countdown to `paused_at` + "Обновить способ оплаты" (portal /
     YooKassa re-payment).
   - `paused`: `--incident-coral-soft` card «Ответы ИИ приостановлены» + the list of what still works (incidents, tasks,
     reminders, notifications) + primary "Оплатить".
   - `cancel_at_period_end`: neutral card «Подписка закончится 30 ноября» + "Возобновить".
3. **Current plan card** (`--radius-card`, clay-lite): plan name (H2) + badge (status), price in the
   workspace currency per interval, next charge date or period end, interval toggle (Monthly / Yearly,
   "2 месяца в подарок") — changing it opens the confirm dialog with the proration note; buttons
   "Сменить тариф" (opens upgrade dialog) and "Управлять оплатой" (Stripe Customer Portal; hidden for
   YooKassa, where "Способ оплаты" opens the saved-method sheet).
4. **Usage meters** — one row per `LimitKey` shown to users (`seats`, `documents`, `storage_mb`,
   `ai_queries_monthly`; `max_pages_per_document` and `max_file_mb` as static facts under the list):
   label + Lucide icon (`Users`, `FileText`, `HardDrive`, `MessagesSquare`), `used / limit` in
   `tabular-nums`, a 8 px rounded bar (`--primary` < 80 %, `--highlight-sun` 80–99 %, `--incident-coral` ≥ 100 % + icon + text
   «лимит достигнут»), unlimited = «без ограничений» and no bar. Explanations under the label:
   - seats: «Веб-пользователи 3 + сотрудники в Telegram 18 (включая приглашённых 2)» from `seats_breakdown`;
     offboarded do not count;
   - documents: drafts + approved, archived do not count;
   - AI questions: «В этом месяце с 1 ноября, сброс 1 декабря (Europe/Moscow)» from `ai_month`; refusals
     count, limit-reached replies do not.
   Each row at ≥ 80 % shows an inline "Увеличить лимит" link (opens the upgrade dialog on that key).
5. **What happens at the limit** — three short lines (mirrors pricing): creation pauses, existing documents
   keep answering, at the AI limit the bot offers to pass questions to a manager.
6. **Invoices** — table (date, number mono, amount in invoice currency, status badge, `ExternalLink` to the
   hosted invoice/receipt); empty: «Счетов пока нет». YooKassa rows link to the 54-ФЗ receipt when present.
7. **Side card** (≥ 1024; below on smaller screens): plan comparison mini-list (next plan's limits vs
   current) + "Все тарифы" link to `/pricing`.

## Upgrade dialog (`UpgradeDialog.vue`, shared)
Opened by "Сменить тариф", by any `errors.limits.<key>` flash (focus key highlighted: «Достигнут лимит
сотрудников: 25 из 25») and by the 80 % banners. Content: interval toggle, plan cards (radio group,
current plan marked, plans below current usage disabled with the reason «у вас 31 сотрудник»), price in
the workspace currency, primary "Перейти к оплате" → `POST /settings/billing/checkout {plan, interval}`
→ redirect to the gateway (fake checkout in tests). Downgrade shows what will be blocked (creation only).
Dialog radius 24 px, focus trapped, `Esc` closes, returns focus to the opener.

## States
Loading: card + 4 meter skeleton rows. Error loading invoices: inline retry inside the table only.
Read-only for `billing.view` without `billing.manage`: all buttons replaced by the caption «Тариф меняет
владелец: Марина Соколова».

## Interactions & a11y
Meters are `role="meter"` with `aria-valuenow/min/max` and a text equivalent; colour never alone (icon +
text at ≥ 80 %). Countdowns update every minute and are not `aria-live` (the banner text carries the
date). Currency formatting via `Intl.NumberFormat(locale, {style:'currency', currency})` from minor units.

## Responsive
≥ 1024: main column + side card; 768 and 375: single column, meters full width, invoices become stacked
rows (date + amount + status on one line, link icon button 44 px).

## data-test
`billing-state-banner` (+ `data-state`), `billing-countdown`, `billing-plan-card`, `billing-interval`,
`billing-change-plan`, `billing-portal`, `billing-meter` (+ `data-limit-key`, `data-level="ok|warn|full"`),
`billing-meter-upgrade`, `billing-invoices`, `billing-invoice-row`, `upgrade-dialog`,
`upgrade-plan-option` (+ `data-plan`), `upgrade-submit`.
