# Pricing — screen spec

Route `/pricing` · page `marketing/Pricing` · public · props `{ plans: Plan[] }` (contract §6.2: prices in
minor units, `limits`, `features`, `highlighted`) · marketing layout, **max-width 1200 px**.

## Purpose
Show four plans (free, starter, pro, business) priced by seats and documents, make "seats include
Telegram-only employees" obvious, and show prices in the visitor's market currency (PLATFORM §11.4).

## Layout
1. Display L title «Тарифы по числу сотрудников и документов» / "Plans by team size and documents";
   subline; **billing interval segmented control** (Monthly / Yearly — "2 месяца в подарок") and a
   **currency select** (USD / EUR / RUB; default from the server-chosen `plans[].currency`, i.e. the
   workspace market for signed-in users, else the locale: `ru` → RUB, `en` → USD; `?currency=` keeps the choice).
2. **4-card grid**: 4 columns at ≥ 1024 (equal height), 2 × 2 at 768, 1 column at 375 (pro first on mobile).
   Card: plan name (H2), short description, price (KPI style: `$79` + caption "в месяц" / "per month";
   yearly shows the yearly price and the per-month equivalent), primary CTA (pro) or secondary CTA
   (others), key limits list with icons (`Users` seats, `FileText` documents, `MessagesSquare` AI questions
   per month, `ScanText` pages per document), features list with `Check`. Pro card: `--primary` 2 px border,
   badge "Популярный" (`--primary-soft`/`--primary-strong`), `--shadow-clay-lg`.
3. **What counts as a seat** card: web users + employees in Telegram, invited ones included; offboarded
   don't count (mirrors ADR-0004 in plain words).
4. **Feature comparison table** (all limit keys + features; sticky first column; on mobile, a plan
   switcher shows one column at a time).
5. **What happens at the limit** — three short items: creation is paused, existing documents keep
   answering, AI question limit → bot offers to pass the question to a manager.
6. **FAQ** — trial (14 days Pro), cancellation, past-due grace (7 days; afterwards only AI answers pause,
   incidents and tasks keep working), **payment: cards via Stripe (USD/EUR) or YooKassa (RUB, with
   54-ФЗ receipts); the gateway follows the country chosen at sign-up**, data on downgrade.
7. Final CTA band.

## Rules
Prices rendered from `prices[currency].{monthly,yearly}_minor` with
`Intl.NumberFormat(locale, {style:'currency', currency, maximumFractionDigits: 0})` (no float maths; RUB shows
«2 490 ₽» in `ru`). No "not supported in your currency" copy. No "Enterprise" or SSO claims. No crossed-out fake prices.

## States
Static; if `plans` is empty (misconfiguration) show the error card with a contact link.

## data-test
`pricing-interval`, `pricing-currency`, `pricing-plan-card` (+ `data-plan="free|starter|pro|business"`), `pricing-plan-cta`,
`pricing-seat-explainer`, `pricing-compare`, `pricing-currency-note`, `pricing-faq`.
