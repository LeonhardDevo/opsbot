# Onboarding — screen spec

Routes `/onboarding/workspace` (foundation `onboarding/CreateWorkspace`, restyled) →
`/onboarding/setup` (`onboarding/Setup`) · ability `workspace.manage` · props contract §5
(`steps`, `bot`, `starterPacks`, `departments`, `employeesCount`, `limits`).

## Purpose
Get a new company from sign-up to "an employee asked the first question" in under 10 minutes; every step
is **skippable** and resumable (state in `workspaces.onboarding`).

## Layout
Centered column, max-width 880 px, on `--bg`. Top: logo + "Выйти из настройки" (ghost link to dashboard).
**Stepper**: 4 steps — Компания (done after workspace creation) → Бот → Регламенты → Команда; each step
shows number, label and state (done `CircleCheck` success, current primary ring, skipped neutral
"пропущено", todo neutral). Horizontal ≥ 768, compact "Шаг 2 из 4" + progress bar at 375.
Step 1 «Компания» (foundation `onboarding/CreateWorkspace`, restyled) adds **Страна** (select; sets currency
and payment gateway: Россия → ₽ / ЮKassa, EU → € / Stripe, other → $ / Stripe — PLATFORM §11.1, shown as a
caption «Цены и оплата: ₽, ЮKassa») and the timezone (prefilled from the browser); data-test
`onboarding-country`, `onboarding-market-caption`.
Each step card (radius 20, padding 32 / 20 mobile): Display M title, 1–2 sentence explanation, content,
footer with "Назад" (ghost), "Пропустить" (ghost) and the primary action.

### Step 2 — Connect your bot
- Left: 4 numbered instructions for BotFather (`/newbot`, choose a name, choose a username ending in
  `bot`, copy the token) with a small static illustration of a BotFather chat (SVG, no Telegram
  screenshots).
- Right: token field (password-type with show/hide `Eye`/`EyeOff`, mono font, `autocomplete="off"`),
  primary "Подключить". Result inline: `BotStatusPill` + `@username`, or a localized error from
  `bot.errors.*` (invalid_token, token_mismatch, already_connected, network, webhook_failed) with a hint.
- Offline/dev hint (only when `driver = fake`): "Use a test token like 123456789:TEST…".
- Skipping keeps the platform bot (if configured) and shows a dashboard reminder card.

### Step 3 — Regulations
Two choice cards (radio-cards): **Стартовый набор для клининга** (8 documents, languages checkboxes RU/EN,
"create departments Residential/Commercial" switch) and **Загрузить свои документы** (dropzone, multiple
files, accepted types, limits). After import/upload: list with processing badges updating live
(`aria-live`), then a callout "Документы — черновики. Проверьте и утвердите их" with a button to the
knowledge page filtered to drafts and a **bulk approve** action (confirm dialog listing titles).

### Step 4 — Team
Inline "Add employee" form (name, department multi-select, primary department, language) → creates the
employee and immediately shows `EmployeeQrCard` with link, expiry countdown, copy and print. List of added
employees with Telegram status updating when they link. Link to "Invite web users (managers)" →
`/settings/members`. Seat usage bar from `limits.seats`.

### Finish
Summary card (bot, documents approved, employees linked) + "Открыть панель" → `/dashboard`.

## States
Loading: skeleton step card. Errors: inline per field + toast for network. Limits reached: inline upgrade
callout instead of the form. Empty step 4 list: illustration (person + QR + chat bubble).

## a11y
Stepper is an ordered list with `aria-current="step"`; focus moves to the step title on change; Back/Skip
never lose entered data.

## data-test
`onboarding-stepper`, `onboarding-step` (+ `data-step`), `onboarding-skip`, `onboarding-back`,
`onboarding-bot-token`, `onboarding-bot-connect`, `onboarding-bot-status`, `onboarding-pack-card`,
`onboarding-pack-import`, `onboarding-upload`, `onboarding-approve-all`, `onboarding-employee-name`,
`onboarding-employee-create`, `onboarding-employee-qr`, `onboarding-finish`.
