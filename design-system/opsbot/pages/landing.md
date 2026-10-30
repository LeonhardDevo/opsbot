# Landing — screen spec

Route `/` · page `marketing/Landing` · public · props `{ canRegister }` · layout: marketing (no app shell).
Tokens, fonts, components: MASTER. Max content width 1200 px.

## Purpose
Convince an owner/ops manager of a cleaning or field-service company (ICP, PLAN §3) in 30 seconds that
OpsBot is *their own Telegram assistant that answers only from approved rules and routes incidents* — and
get them to "Start free".

## Sections (order fixed)
1. **Header** (sticky, 64 px, `--bg` 85 % + backdrop blur): `AppLogoMark` + wordmark; nav anchors (How it
   works, For cleaning, Pricing, Security); locale switch (RU/EN); theme toggle (`Sun`/`Moon`/`Laptop`);
   "Sign in" (ghost); "Start free" (primary). Mobile: logo + menu button (`Menu`) opening a sheet.
2. **Hero** — two columns (≥ 1024), stacked below. Left: eyebrow chip "Telegram-бот для сервисных
   компаний"; Display XL «Регламенты компании — в Telegram у каждого сотрудника» / "Your company rules in
   every employee's Telegram"; one-paragraph subline (answers from approved documents with a link to the
   source; incidents straight to the responsible manager); CTAs "Начать бесплатно" (primary) + "Как это
   работает" (secondary, anchor); micro-note "Без приложения для сотрудников · RU / EN". Right:
   `TelegramPreview` built from real `ChatBubble` components: employee «Поцарапала стол у клиента, что
   делать?» → bot answer (3 steps) with two citation chips and buttons «Оформить инцидент» / «Передать
   менеджеру»; a floating card "Инцидент № 12 → Олег Петров" (severity badge medium). Background: two
   large blurred circles in `--primary-soft` and `--accent-mint-soft` (≤ 60 % opacity, static under reduced
   motion).
3. **Proof strip** — four facts with Lucide icons, no fake customer logos: `Bot` "Ваш собственный бот",
   `FileCheck` "Только утверждённые документы", `Languages` "Русский и английский", `ShieldCheck`
   "Ссылки на источники живут 30 минут".
4. **Chat story** (`id="story"`; OpsBot's signature section, MASTER §4.1 — not a bento grid): at ≥ 1024 a
   **sticky phone frame** (left, 380 px, built from real `ChatBubble`/`CitationChip`/`IncidentCard`
   components) and a column of six scene cards (right). As each scene card enters the viewport
   (IntersectionObserver, no scroll-jacking) the phone shows that scene: (a) employee question → answer
   with two citation chips (approved-only rules); (b) tap on a chip → the signed viewer excerpt with
   highlight; (c) a question outside the rules → honest refusal + «Передать менеджеру»; (d) manager reply
   labelled «не из регламента»; (e) «Оформить инцидент» → incident card «№ 12 → Олег Петров»; (f) quiet-hours
   reminder at 08:00 with «Готово». Scene cards carry the H3 + two lines of copy + the Lucide icon of the
   feature. Below 1024 each scene card embeds its own static phone snippet (no sticky). Reduced motion: no
   transitions, the phone shows the scene of the focused/visible card instantly.
5. **How it works** (`id="how"`) — 4 numbered steps mirroring onboarding: connect your bot → upload and
   approve regulations (or the starter pack) → invite staff by QR → staff ask, managers act. Horizontal
   with connector line ≥ 1024, vertical list below.
6. **For cleaning companies** — the 8 starter-pack titles as document chips + note "Шаблоны — адаптируйте
   под свою компанию перед утверждением"; incident categories with their icons.
7. **Comparison** — table with categories (not competitor logos): "Офисный ИИ-поиск", "Приложения-
   справочники", "OpsBot" × rows from PLAN §2; cells use `Check`/`Minus` icons + short text (never icon
   alone). Mobile: each row becomes a card.
8. **Security & privacy** — 4 cards: encrypted bot tokens, signed links, employee notice and scoped
   access, local/mock AI keeps data in your deployment; link to `/security`.
9. **Pricing teaser** — 4 compact plan cards (price per month, seats, documents) + "Все тарифы" link.
   Prices in the visitor's market currency (USD / EUR / RUB, `Intl.NumberFormat`, PLATFORM §11.4) with a
   currency switch; note «Оплата картой через Stripe или ЮKassa (для рублей)» / "Pay by card via Stripe,
   or YooKassa for RUB".
10. **FAQ** (accordion, reka-ui): staff app needed? languages? what if the bot does not know? our own bot?
    where is data stored? can we pay in rubles (yes — YooKassa)?
11. **Final CTA** band (`--primary-soft` card) + footer with legal links (Terms, Privacy, Cookies, DPA,
    Sub-processors, AUP, AI notice, Employee notice, Security, Contact), locale + theme controls.

## States
Static page; the only async element is none. Images: none (all SVG/components). LCP target: hero title.

## Interactions & a11y
Anchor links with `scroll-margin-top: 80px`; accordion keyboard support; preview is decorative
(`aria-hidden="true"` with a visually hidden text summary). Reduced motion: no float/parallax.

## Responsive
375: single column, hero preview below the CTAs, chat story as stacked scene cards with inline phone
snippets, comparison as cards. 768: same as 375 with wider snippets.
1440: as described. No horizontal scroll; Display XL clamps so «Регламенты компании —» fits at 375.

## data-test
`landing-hero`, `landing-cta-start`, `landing-cta-how`, `landing-preview`, `landing-story`, `landing-story-scene` (+ `data-scene`), `landing-story-phone`,
`landing-how`, `landing-pack`, `landing-compare`, `landing-security`, `landing-pricing`, `landing-faq`,
`landing-locale-switch`, `landing-theme-toggle`.
