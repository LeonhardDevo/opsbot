# OpsBot — Design System (MASTER)

> **How to use.** Build every screen from this file plus its screen spec in `pages/<page>.md`. A page
> spec may *add* layout, components and states; it never redefines tokens, fonts, radii or icon rules.
> Written for OpsBot per PLATFORM.md §0 and §9. Style references: "Soft UI Evolution" for contrast-safe
> soft depth, "Claymorphism" for the radius/press language; charts follow the data-viz method of §8.

---

## 1. Brand

| | |
|---|---|
| Personality | friendly, operational, Telegram-native, fresh. Calm help for people at work, not a toy. |
| Style | **soft-UI / clay-lite**: rounded 20 px surfaces, two-layer soft shadows, 1 px hairline borders (not the thick 3–4 px clay borders), chat-first layouts, citation chips. Depth comes from shadow + tint, never from gradients on data UI. |
| Promise in UI | "Answer from approved rules, with the source" → every AI answer shows its sources; refusals are honest; manager answers are visibly different. |
| Voice | Short sentences, verbs first, no jargon. RU: «вы» (lower-case), no bureaucratese. EN: plain, second person. Never cute in error messages. |
| Logo | `AppLogoMark.vue`: inline SVG — rounded square (radius 28 % of size) in `--primary`, a white speech bubble whose tail turns into a check mark in `--accent-mint`; monochrome variant uses `currentColor`. Wordmark "OpsBot" in Unbounded 600. |
| Imagery | Inline SVG illustrations from geometric shapes (rounded rectangles, circles), product UI fragments (chat bubbles, document sheets, citation chips) and Lucide glyphs. No stock photos, no 3D stickers, no emoji, no clip-art. |

---

## 2. Colour tokens (both themes designed, measured)

Implemented in `resources/css/brand.css` as OKLCH custom properties in `:root` (light) and `.dark`
(dark; the starter-kit appearance script toggles the class, "system" follows `prefers-color-scheme`),
mapped into Tailwind 4 via `@theme inline` (`--color-primary: var(--primary)` …). **Components never use
hex literals or Tailwind palette colours (`bg-white`, `text-gray-500`, `#E2E8F0`) — only these tokens.**

| Token | Light (hex · OKLCH) | Dark (hex · OKLCH) |
|---|---|---|
| `--bg` | `#F6FAFD` · `oklch(98.3% 0.006 239.8)` | `#0A1220` · `oklch(18.2% 0.031 261.1)` |
| `--surface` | `#FFFFFF` · `oklch(100% 0 0)` | `#111C2E` · `oklch(22.6% 0.039 259.9)` |
| `--surface-2` | `#EEF5FB` · `oklch(96.7% 0.011 243.7)` | `#16243A` · `oklch(26.0% 0.046 259.1)` |
| `--popover` | `#FFFFFF` · `oklch(100% 0 0)` | `#16243A` · `oklch(26.0% 0.046 259.1)` |
| `--border` (decorative hairline) | `#D8E4EE` · `oklch(91.3% 0.019 243.0)` | `#23344D` · `oklch(32.3% 0.050 257.9)` |
| `--input` (control borders, ≥ 3:1) | `#8197AC` · `oklch(66.7% 0.040 247.2)` | `#566D8A` · `oklch(52.8% 0.054 254.5)` |
| `--ring` (focus) | `#0284C7` · `oklch(58.8% 0.139 242.0)` | `#38BDF8` · `oklch(75.4% 0.139 232.7)` |
| `--fg` | `#0B1B2B` · `oklch(21.7% 0.038 250.0)` | `#E6EEF7` · `oklch(94.6% 0.015 251.2)` |
| `--muted-fg` | `#4B5E72` · `oklch(47.4% 0.040 250.0)` | `#9AAEC4` · `oklch(74.3% 0.039 250.8)` |
| `--primary` (sky) | `#0277B6` · `oklch(54.7% 0.131 242.8)` | `#38BDF8` · `oklch(75.4% 0.139 232.7)` |
| `--primary-fg` | `#FFFFFF` · `oklch(100% 0 0)` | `#04263A` · `oklch(25.6% 0.053 239.6)` |
| `--primary-soft` | `#E0F2FE` · `oklch(95.1% 0.025 236.8)` | `#0C2F47` · `oklch(29.3% 0.059 242.8)` |
| `--primary-strong` | `#075985` · `oklch(44.3% 0.100 240.8)` | `#7DD3FC` · `oklch(82.8% 0.101 230.3)` |
| `--accent-mint` | `#14B8A6` · `oklch(70.4% 0.123 182.5)` | `#2DD4BF` · `oklch(78.5% 0.133 181.9)` |
| `--accent-mint-fg` | `#04302C` · `oklch(28.0% 0.046 185.7)` | `#042F2C` · `oklch(27.6% 0.045 187.9)` |
| `--accent-mint-strong` | `#0F766E` · `oklch(51.1% 0.086 186.4)` | `#5EEAD4` · `oklch(85.5% 0.125 181.1)` |
| `--accent-mint-soft` | `#DDF7F2` · `oklch(95.6% 0.028 182.9)` | `#0B2E2E` · `oklch(27.7% 0.039 195.1)` |
| `--highlight-sun` | `#FACC15` · `oklch(86.1% 0.173 91.9)` | `#FACC15` · `oklch(86.1% 0.173 91.9)` |
| `--highlight-sun-fg` | `#3F2A00` · `oklch(30.3% 0.063 79.3)` | `#3F2A00` · `oklch(30.3% 0.063 79.3)` |
| `--highlight-sun-strong` | `#854D0E` · `oklch(47.6% 0.103 61.9)` | `#FDE68A` · `oklch(92.4% 0.115 95.7)` |
| `--highlight-sun-soft` | `#FEF6C7` · `oklch(96.8% 0.061 99.4)` | `#33290A` · `oklch(28.5% 0.048 91.0)` |
| `--incident-coral` | `#FB7185` · `oklch(71.9% 0.169 13.4)` | `#FB7185` · `oklch(71.9% 0.169 13.4)` |
| `--incident-coral-fg` | `#4C0519` · `oklch(27.1% 0.101 12.1)` | `#4C0519` · `oklch(27.1% 0.101 12.1)` |
| `--incident-coral-strong` | `#BE123C` · `oklch(51.4% 0.198 16.9)` | `#FDA4AF` · `oklch(81.0% 0.106 11.6)` |
| `--incident-coral-soft` | `#FFE4E8` · `oklch(94.2% 0.030 7.5)` | `#3A1724` · `oklch(26.2% 0.058 358.4)` |
| `--success` | `#15803D` · `oklch(52.7% 0.137 150.1)` | `#4ADE80` · `oklch(80.0% 0.182 151.7)` |
| `--success-soft` | `#DCFCE7` · `oklch(96.2% 0.043 156.7)` | `#0F2E1D` · `oklch(27.2% 0.049 156.8)` |
| `--warning` (text) | `#A16207` · `oklch(55.4% 0.121 66.4)` | `#FBBF24` · `oklch(83.7% 0.164 84.4)` |
| `--destructive` | `#BE123C` · `oklch(51.4% 0.198 16.9)` | `#FB7185` · `oklch(71.9% 0.169 13.4)` |
| `--destructive-fg` | `#FFFFFF` · `oklch(100% 0 0)` | `#4C0519` · `oklch(27.1% 0.101 12.1)` |
| `--neutral-soft` | `#E9EFF5` · `oklch(94.9% 0.010 247.9)` | `#1C2A40` · `oklch(28.4% 0.045 259.2)` |
| `--neutral-fg` | `#3A4B5E` · `oklch(40.6% 0.039 251.6)` | `#C3D0DE` · `oklch(85.2% 0.024 250.1)` |
| `--mark` (cited excerpt) | `#FDE68A` · `oklch(92.4% 0.115 95.7)` | `#5C4A0B` · `oklch(41.6% 0.080 91.5)` |

PLATFORM §9 anchors kept: sky `#0284C7`/`#38BDF8`, mint `#14B8A6`, sun `#FACC15`, coral `#FB7185`,
bg `#F6FAFD`/`#0A1220`. The light `--primary` is tuned from `#0284C7` to `#0277B6` because white text on
`#0284C7` measures 4.10:1; `#0284C7` remains the focus ring and chart colour.

### 2.1 Measured contrast pairs (WCAG 2.x; script in the PR that adds `brand.css` must re-check)

| Pair | Light | Dark | Use |
|---|---|---|---|
| `--fg` on `--bg` / `--surface` | 16.59 / 17.41 | 16.01 / 14.59 | body text |
| `--muted-fg` on `--bg` / `--surface` / `--surface-2` | 6.37 / 6.68 / 6.07 | 8.23 / 7.50 / 6.85 | secondary text |
| `--primary-fg` on `--primary` | 4.86 | 7.30 | primary buttons |
| `--primary` on `--bg` / `--surface` | 4.64 / 4.86 | — / 7.97 | links on plain surfaces |
| `--primary-strong` on `--primary-soft` / `--surface-2` | 6.59 / 6.87 | 8.32 / 9.35 | chips, info badges, links on tints |
| `--accent-mint-fg` on `--accent-mint` | 5.76 | 7.79 | mint fills |
| `--accent-mint-strong` on `--surface` / `--accent-mint-soft` | 5.47 / 4.86 | 11.55 / 9.83 | mint text |
| `--highlight-sun-fg` on `--highlight-sun` | 8.89 | 8.89 | sun fills |
| `--highlight-sun-strong` on `--highlight-sun-soft` | 6.27 | 11.53 | medium severity, manager label |
| `--fg` on `--highlight-sun-soft` | 15.94 | 12.27 | manager bubble text |
| `--incident-coral-fg` on `--incident-coral` | 5.81 | 5.81 | coral fills |
| `--incident-coral-strong` on `--surface` / `--incident-coral-soft` | 6.29 / 5.24 | 9.03 / 8.36 | high severity, overdue |
| `--destructive-fg` on `--destructive` | 6.29 | 5.81 | destructive buttons |
| `--success` on `--surface` / `--success-soft` | 5.02 / 4.57 | 9.80 / 8.43 | success text and badges |
| `--warning` on `--surface` | 4.92 | 10.23 | warning text |
| `--neutral-fg` on `--neutral-soft` | 7.72 | 9.21 | neutral badges |
| `--fg` on `--mark` | 13.98 | 7.35 | highlighted excerpt |
| `--input` on `--surface` (non-text) | 3.02 | 3.21 | input borders (≥ 3:1) |
| `--ring` on `--bg` (non-text) | 3.90 | 8.75 | focus ring |

Rule: `--primary` as **text** only on `--bg`/`--surface` (light `--primary` on `--surface-2` is 4.42 —
use `--primary-strong` on tints). Mint, sun and coral base colours are **fills** with their `-fg`
partner, never text on light surfaces.

### 2.2 Elevation (clay-lite)
```css
:root {
  --shadow-clay-sm: 0 1px 0 0 oklch(100% 0 0 / 0.9) inset, 0 1px 2px oklch(21.7% 0.038 250 / 0.06);
  --shadow-clay:    0 1px 0 0 oklch(100% 0 0 / 0.9) inset, 0 1px 2px oklch(21.7% 0.038 250 / 0.06),
                    0 8px 24px -8px oklch(21.7% 0.038 250 / 0.14);
  --shadow-clay-lg: 0 1px 0 0 oklch(100% 0 0 / 0.9) inset, 0 2px 4px oklch(21.7% 0.038 250 / 0.06),
                    0 18px 40px -12px oklch(21.7% 0.038 250 / 0.20);
  --shadow-pressed: inset 0 2px 4px oklch(21.7% 0.038 250 / 0.12);
  --overlay: oklch(18.2% 0.031 261 / 0.55);
}
.dark {
  --shadow-clay-sm: 0 1px 0 0 oklch(100% 0 0 / 0.05) inset, 0 1px 2px oklch(0% 0 0 / 0.40);
  --shadow-clay:    0 1px 0 0 oklch(100% 0 0 / 0.05) inset, 0 1px 2px oklch(0% 0 0 / 0.40),
                    0 10px 28px -10px oklch(0% 0 0 / 0.60);
  --shadow-clay-lg: 0 1px 0 0 oklch(100% 0 0 / 0.06) inset, 0 2px 4px oklch(0% 0 0 / 0.45),
                    0 22px 48px -14px oklch(0% 0 0 / 0.70);
  --shadow-pressed: inset 0 2px 6px oklch(0% 0 0 / 0.45);
  --overlay: oklch(0% 0 0 / 0.60);
}
```
In dark mode depth is carried mostly by `--surface` → `--surface-2` steps; shadows are subtler.

---

## 3. Typography

```css
/* resources/css/app.css — package index CSS includes the cyrillic + cyrillic-ext + latin subsets */
@import '@fontsource-variable/unbounded';
@import '@fontsource-variable/onest';
@import '@fontsource-variable/jetbrains-mono';
@theme inline {
  --font-display: 'Unbounded Variable', 'Onest Variable', system-ui, sans-serif;
  --font-sans: 'Onest Variable', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  --font-mono: 'JetBrains Mono Variable', ui-monospace, 'Cascadia Code', monospace;
}
```
Do **not** import the latin-only files (`/latin.css`, `/wght.css` subsets); the starter-kit Bunny fonts
(`fonts:` option, `@fonts` directive) are removed (FOUNDATION_CONTRACT §1).

| Role | Font | Size / line-height | Weight | Notes |
|---|---|---|---|---|
| Display XL (landing hero) | Unbounded | `clamp(1.75rem, 5vw, 3.5rem)` / 1.1 | 600 | `letter-spacing: -0.01em`, `text-wrap: balance` |
| Display L (landing section titles, pricing title) | Unbounded | `clamp(1.5rem, 3.5vw, 2.5rem)` / 1.15 | 600 | |
| Display M (onboarding step title, dashboard greeting, empty-state title ≥ 24 px only) | Unbounded | `1.5rem` / 1.2 | 500 | |
| H1 (app page title) | Onest | `1.5rem` / 1.25 | 650 | |
| H2 (card/section title) | Onest | `1.125rem` / 1.35 | 600 | |
| H3 / labels | Onest | `0.9375rem` / 1.4 | 600 | |
| Body | Onest | `1rem` / 1.6 | 400 | inputs always ≥ 16 px (no iOS zoom) |
| Dense body (tables, chat meta) | Onest | `0.875rem` / 1.5 | 400–500 | |
| Caption | Onest | `0.8125rem` / 1.4 | 500 | `--muted-fg` |
| KPI value | Onest | `1.75rem` / 1.1 | 650 | `font-variant-numeric: tabular-nums` |
| Mono (ids `№ 12`, `Q-15`, `T-34`, token hints, @usernames, timestamps in audit) | JetBrains Mono | `0.8125rem` / 1.4 | 500 | |

Rules: **Unbounded is never used below 24 px** and never for body, buttons, tables or Telegram previews.
`<html lang="ru|en">` is always set; long Russian words: `hyphens: auto` on paragraphs and table cells,
`overflow-wrap: anywhere` on chips, badges and citation titles; headings `text-wrap: balance`. Check every
heading at 375 px in Russian ("Регламенты и инструкции", "Неотвеченные вопросы") — no overflow, no
ellipsis on page titles. Numbers in tables and KPIs use `tabular-nums`.

---

## 4. Shape, spacing, layout

| Token | Value | Use |
|---|---|---|
| `--radius-card` | 20 px | cards, kanban cards, chat bubbles, QR tile, pricing cards, popovers |
| `--radius-dialog` | 24 px | dialogs, sheets |
| `--radius-control` | 14 px | buttons, inputs, selects, segmented controls, tabs |
| `--radius-chip` | 999 px | chips, badges, pills, citation chips, avatars |
| Bubble tail | the corner nearest the speaker is 6 px | chat bubbles |

Spacing scale 4 px base: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64, 96. Card padding 20 px (mobile 16 px).
Grid: app content max-width 1440 px, side padding 24 px (16 px < 768 px), 12-column grid with 24 px gaps.
Breakpoints: 375 (base), 768 (`md`), 1024 (`lg`), 1440 (`xl`). Marketing max-width 1200 px.
Touch targets ≥ 44 × 44 px everywhere (icon buttons included, visual size may be 36 px with padding).

### 4.1 Layout language (OpsBot only — PLATFORM §0.10: not a recolor of PayFlow or ScamPause)

OpsBot's app is a **messenger-style operations desk**: it looks like the place where the company's
Telegram conversations land, not like a finance dashboard. The foundation sidebar layout
(`AppSidebarLayout`) is replaced by `OpsShell.vue` (`resources/js/layouts/OpsShell.vue`, web-ui).

| Aspect | OpsBot | (for contrast) PayFlow / ScamPause |
|---|---|---|
| Shell | **72 px icon rail** on `--surface-2` (Telegram-desktop "folders" feel): 48 px rounded-16 icon tiles with a 11 px caption under each icon (no text-only nav, no collapsible 264 px sidebar), workspace avatar on top, user avatar + theme/locale at the bottom; badge counts as pills on tiles. Content sits on a **floating sheet** (`--surface`, `--radius-dialog` on the top-left corner only, `--shadow-clay-sm`) inset 8 px from the rail and the top bar | PayFlow: top-bar "ledger" shell; ScamPause: ink sidebar 264/64 + 56 px header |
| Top bar | 56 px, no breadcrumbs: page title (H1) left; **company-bot status pill** (`BotStatusPill`: dot + `@username` + state, links to `/bot`) centre-left; ⌘K search, notifications (`Bell`), "Ask the bot" button right | — |
| Live desk | persistent **right column 360 px at ≥ 1440** (toggle sheet via `Inbox` button at 1024–1439, hidden < 1024): a chat-bubble stream of new incidents, escalated questions, overdue tasks and bot status events (newest at the bottom, like a chat), each bubble with quick actions «Взять» / "Take", «Ответить» / "Answer", «Готово» / "Done"; polls `GET /desk/feed` (deferred prop, 30 s, ADR-0018 polling); `aria-live="polite"` with a counter, not per item | ScamPause's right rail is a static "What to do next" checklist on case files only |
| Phones (< 768) | **bottom tab bar** 64 px (Desk, Incidents, Tasks, Questions, More) with badge pills; the rail is hidden; More opens a sheet with the remaining nav | — |
| Density | comfortable: table rows 52 px, list rows as 2-line "conversation rows" (avatar/icon, title, excerpt, time on the right), cards 20 px padding; KPI tiles are rare (analytics only) | PayFlow dense ledger tables |
| Radii / depth | 20 / 24 / 14 / pill (§4 table), clay-lite shadows, hairline borders | — |
| Typography | Unbounded display ≥ 24 px only, Onest UI, JetBrains Mono ids (§3) | Manrope / IBM Plex |
| Illustration | rounded geometric chat scenes: bubbles, document sheets with citation chips, QR tiles, a simple person made of circles; mint + sky + sun accents; never charts as decoration | — |
| Dashboard | a **"Today" conversation feed** (`pages/dashboard.md`), not KPI tiles + card grid | — |
| Landing | **chat-story**: a sticky phone conversation that plays scenes as the visitor scrolls (`pages/landing.md`), not a bento grid | PayFlow: fintech bento |

Rules: one primary action per page in the page header; the Live desk never duplicates the page's own
list (on `/incidents` it hides incident bubbles); the document viewer (`/s/doc`) and marketing pages do
not use `OpsShell`. data-test hooks: `shell-rail`, `shell-rail-item` (+ `data-nav`), `shell-topbar`,
`shell-bot-pill`, `shell-desk`, `shell-desk-item` (+ `data-kind`), `shell-desk-action`, `shell-desk-toggle`,
`shell-tabbar`, `shell-tabbar-item`.

---

## 5. Motion

Durations 120 ms (press), 180 ms (hover, colour), 240 ms (enter/exit, sheets), easing
`cubic-bezier(0.2, 0.8, 0.2, 1)`. Press = `--shadow-pressed` + `translateY(1px)` (no scale on data UI).
Hover never shifts layout. Landing illustrations may float (≤ 6 px, 6 s loop) and use a soft overshoot on
enter. Under `prefers-reduced-motion: reduce`: no transforms, no loops, no shimmer, spinners become a
static icon + text ("Подключаем…"), typing dots become static "Думаю…" / "Thinking…".

---

## 6. Iconography

**Lucide only** (`@lucide/vue`, already in the kit; tree-shaken imports; every name below was checked
against `@lucide/vue` 1.x — Lucide renames icons between majors, so re-check on upgrades). Brand marks (Telegram, Stripe)
from `simple-icons` as local SVG paths. No emoji, no Heroicons, no icon fonts. Icon size 16 px in dense
UI, 20 px default, 24 px in empty states/cards; stroke 1.75. Icon-only buttons need `aria-label` and a
tooltip.

| Concept | Lucide icon |
|---|---|
| Nav: dashboard / assistant / knowledge / incidents / tasks / unanswered / employees / departments / bot / analytics / audit / settings / billing | `LayoutDashboard` / `MessagesSquare` / `BookOpen` / `Siren` / `ListChecks` / `MessageCircleQuestionMark` / `Users` / `Building` / `Bot` / `ChartColumn` / `ScrollText` / `Settings` / `CreditCard` |
| Shell: Live desk / more (tab bar) / usage meter / upgrade | `Inbox` / `Ellipsis` / `Gauge` / `CircleArrowUp` |
| Incident category: damage / complaint / no_access / injury / lost_item / other | `Armchair` / `MessageSquareWarning` / `DoorClosed` / `Bandage` / `PackageSearch` / `CircleEllipsis` |
| Severity: low / medium / high | `SignalLow` / `SignalMedium` / `SignalHigh` |
| Incident status: open / in_progress / resolved / closed | `CircleDot` / `CircleDashed` / `CircleCheck` / `Archive` |
| Task status: todo / in_progress / done / cancelled; overdue | `Circle` / `Timer` / `CircleCheckBig` / `CircleSlash`; `AlarmClock` |
| Document: draft / approved / archived / processing / failed / review due / OCR | `FilePen` / `FileCheck` / `FileArchive` / `FileClock` / `FileX` / `CalendarClock` / `ScanText` |
| Bot: active / connecting / invalid or revoked / disconnecting / disconnected | `Bot` / `LoaderCircle` / `KeyRound` / `LoaderCircle` / `Unplug` |
| Telegram link: linked / not linked / blocked; QR | `Link` / `Link2Off` / `Ban`; `QrCode` |
| Sources / open source / confidence / pass to manager / manager answer / routing | `BookOpenText` / `ExternalLink` / `Gauge` / `Forward` / `UserCheck` / `Route` |

---

## 7. Components (extend shadcn-vue; tokens only)

**Buttons** (height 44 px, radius 14, padding-x 18, Onest 600 15 px, `--shadow-clay-sm`):
primary (`--primary`/`--primary-fg`; hover `color-mix(in oklch, var(--primary) 90%, var(--fg))`),
secondary (`--surface`, 1 px `--border`, `--fg`), ghost (transparent, hover `--surface-2`), destructive
(`--destructive`/`--destructive-fg`), link (`--primary`, underline on hover). Focus-visible: 3 px
`--ring` outline, 2 px offset in `--bg`. Disabled: 50 % opacity + `cursor: not-allowed`. Loading: spinner +
original label kept (width does not jump). All clickables `cursor: pointer`.

**Inputs / textarea / select / slider / switch**: height 44 px, radius 14, 1 px `--input` border,
`--surface` background, placeholder `--muted-fg`, focus ring as buttons, error state border
`--destructive` + message with `CircleAlert` icon below (`aria-describedby`). Labels always visible above
fields. Slider (confidence threshold) shows the value and a tick at the default (0.40).

**Card**: `--surface`, 1 px `--border`, radius 20, `--shadow-clay`; interactive card hover → `--shadow-clay-lg`
(no translate). Card header: H2 + optional caption + actions.

**Citation chip** (`CitationChip.vue`): inline `button`/`a`, radius 999, height 28 px, padding 4 px 10 px
4 px 4 px, `--surface-2` background, 1 px `--border`, text `--primary-strong` Onest 500 13 px; leading
index bubble 20 px circle `--primary`/`--primary-fg` with JetBrains Mono 12 px number; label
`Title · p. 3`, max-width 280 px with ellipsis and full title in a tooltip; `overflow-wrap: anywhere`.
Hover border `--primary`; focus ring; opens the source in a new tab; `aria-label="Источник 1: <title>,
страница 3"`. Chips wrap in a row under the answer with 8 px gaps.

**Chat bubbles** (`ChatBubble.vue`, variant `employee | bot | manager | system`): radius 20 with a 6 px
corner toward the speaker; max-width 640 px (85 % on mobile); padding 12 × 16; text 15 px/1.55.
- `employee` — right-aligned, `--primary-soft` background, `--fg` text.
- `bot` — left-aligned, `--surface` + 1 px `--border`, header row `Bot` icon + "OpsBot" caption; below:
  citation chips, confidence meter, action buttons (secondary, small 36 px visual / 44 px hit area).
  Refused answers add a 3 px left bar `--incident-coral-strong` and the "Pass to manager" button.
- `manager` — left-aligned, `--highlight-sun-soft` background, header `UserCheck` + label
  «Ответ менеджера (не из регламента)» / "Manager's answer (not from the regulations)" in
  `--highlight-sun-strong` 12 px 600, signature "— Name" caption.
- `system` — centred caption `--muted-fg` (e.g. "Incident № 12 created").
Timestamps caption below each bubble. Typing indicator: 3 dots 6 px `--muted-fg` (static text under
reduced motion).

**Confidence meter** (`ConfidenceMeter.vue`): `role="meter"`, `aria-valuemin=0`, `aria-valuemax=1`,
`aria-valuenow`, `aria-valuetext="Высокая уверенность, 82 %"`. Visual: `Gauge` icon 14 px + bar 80 × 6 px
(radius 999, track `--neutral-soft`) + text "82 % · высокая". Fill by band (contract §9.7): high
`--accent-mint-strong` (light) / `--accent-mint` (dark); medium `--warning`; low `--destructive`. A 2 px
tick marks the workspace threshold. Never colour alone — the band word is always shown.

**Incident severity badge**: chip, `Signal*` icon + label; low `--neutral-soft`/`--neutral-fg`; medium
`--highlight-sun-soft`/`--highlight-sun-strong`; high `--incident-coral-soft`/`--incident-coral-strong`
with 1 px border `color-mix(in oklch, var(--incident-coral-strong) 35%, transparent)`.

**Incident status badge**: open `--primary-soft`/`--primary-strong`; in_progress
`--highlight-sun-soft`/`--highlight-sun-strong`; resolved `--success-soft`/`--success`; closed
`--neutral-soft`/`--neutral-fg`. Icons per §6. Category chip: neutral + category icon.

**Task status / due chip**: status badges as incidents (todo neutral, in_progress primary, done success,
cancelled neutral with line-through label). Due chip: `CalendarClock` + local date/time; overdue →
`--incident-coral-soft`/`--incident-coral-strong` + `AlarmClock` + "просрочено на 2 ч".

**Bot-status pill** (`BotStatusPill.vue`): pill with 8 px dot + label + `@username` in mono.
active `--success-soft`/`--success`; connecting & disconnecting `--primary-soft`/`--primary-strong` with
`LoaderCircle` (spins 1 s linear; static under reduced motion); invalid_token & revoked
`--incident-coral-soft`/`--incident-coral-strong` + `KeyRound`; disconnected `--neutral-soft`/`--neutral-fg`
+ `Unplug`. Status changes are announced via an `aria-live="polite"` region.

**Document status badges** (two independent badges): processing — queued (neutral, `FileClock`),
processing (primary, "OCR 12 / 150" with a thin progress bar), failed (coral, `FileX`, error text in a
tooltip and in the row); approval — draft (neutral, 1 px dashed `--input` border, `FilePen`), approved
(success, `FileCheck`), archived (neutral, `FileArchive`, title in `--muted-fg`). Extra: review overdue
(sun soft, `CalendarClock`), future effective date (primary soft, "с 22 нояб." / "from Nov 22").

**Employee Telegram status**: linked (success, `Link`, `@username`), not linked (neutral, `Link2Off`,
"Выдать ссылку" button), blocked (coral, `Ban`, tooltip "Сотрудник заблокировал бота").

**KPI tile**: card, caption label, KPI value, delta chip (`TrendingUp`/`TrendingDown` + "+12 %" text;
success/destructive soft colours; neutral when null "—"), optional 14-day sparkline (`--chart-1`, 2 px).

**QR tile** (`EmployeeQrCard.vue`): QR always dark-on-light for scannability — `#0B1B2B` modules on
`#FFFFFF` with a 16 px quiet zone inside a radius-20 tile even in dark theme (documented exception to
"tokens only"); caption with expiry countdown; buttons Copy link / Print / New link. Rendered as inline
SVG by the `qrcode` npm package; never a remote QR service.

**Data table** (@tanstack/vue-table): header `--surface-2` sticky, 14 px 600; rows 52 px, hover
`--surface-2`, selected `--primary-soft`; numbers right-aligned tabular; row actions in a kebab menu with
`aria-label`; below 768 px rows become stacked cards (label/value pairs). Pagination + per-page select.

**Kanban board** (incidents, tasks): columns per status with count badge; cards radius 20, 12 px gap;
drag-and-drop with keyboard alternative (status select on the card menu); a drop that violates the state
machine snaps back with a toast explaining why.

**Empty / loading / error states**: every list has all three. Empty = 160 px inline SVG illustration
(two-tone geometric scene built from tokens: `--primary-soft`, `--accent-mint-soft`,
`--highlight-sun-soft` fills, `--border` and `--primary` strokes, one Lucide glyph) + Display-M or H2
title + one sentence + primary CTA. Loading = skeleton blocks in `--surface-2` (shimmer only without
reduced motion), never a spinner for > 300 ms content. Error = `CircleAlert` card with message, request
id in mono and "Try again".

**Dialogs / sheets**: radius 24, `--popover`, overlay `--overlay` + 4 px backdrop blur, focus trap,
Esc closes, destructive confirms state the consequence in one sentence and repeat the object name.

**Toasts**: sonner themed with tokens; success/error icons; 5 s; actions in toasts are buttons.

**Telegram preview** (`TelegramPreview.vue`, landing + assistant + bot settings): a phone-frame card
rendering real `ChatBubble` components with inline keyboard rows (secondary small buttons), using the
`simple-icons` Telegram mark only in the frame header.

---

## 8. Charts (vue-echarts, tree-shaken ECharts)

Method: form → colour → validate → marks → hover → accessibility. Charts sit on
`--surface` cards; palettes are contrast-checked against `#FFFFFF` (light) and `#111C2E`
(dark).

| Token | Light | Dark |
|---|---|---|
| `--chart-1` sky | `#0284C7` · `oklch(58.8% 0.139 242.0)` | `#2B93CF` · `oklch(63.3% 0.128 239.5)` |
| `--chart-2` coral-orange | `#E8664A` · `oklch(66.6% 0.168 33.7)` | `#DD6446` · `oklch(64.7% 0.159 35.1)` |
| `--chart-3` teal | `#0D9488` · `oklch(60.0% 0.104 184.7)` | `#16A08F` · `oklch(63.5% 0.110 181.5)` |
| `--chart-4` amber | `#C28A00` · `oklch(67.1% 0.139 79.8)` | `#B88A05` · `oklch(66.0% 0.134 84.6)` |
| `--chart-5` violet | `#7C5CD6` · `oklch(56.9% 0.180 291.8)` | `#8B74E2` · `oklch(63.1% 0.161 290.7)` |
| `--chart-6` rose | `#D9467A` · `oklch(61.4% 0.187 2.8)` | `#D4518A` · `oklch(62.4% 0.174 356.3)` |
| `--chart-grid` | `#E3ECF4` | `#1E2D44` |
| `--chart-axis` (labels, 12 px) | `#4B5E72` (6.68:1) | `#9AAEC4` (7.50:1) |
| `--chart-tooltip-bg` / `--chart-tooltip-fg` | `#0B1B2B` / `#F6FAFD` (16.59:1) | `#E6EEF7` / `#0A1220` (16.01:1) |

Validation result (adjacent pairs, the order above is fixed): light — lightness band, chroma floor, CVD
separation (worst ΔE 8.6), normal-vision floor (worst ΔE 20.6) and contrast ≥ 3:1 all **pass**; dark —
all **pass** (worst CVD ΔE 11.6, normal-vision 18.2). Sky vs teal fails the all-pairs floor, so OpsBot uses
**no scatter, bubble or choropleth charts**; donuts are not used (use a 100 % stacked bar).

**Sequential (sky, magnitude only; reserved for v1 heatmaps):** light `#E0F2FE → #BAE6FD → #7DD3FC →
#38BDF8 → #0EA5E9 → #0284C7 → #0369A1 → #075985 → #0C4A6E` (low → high); dark `#0F2A40 → #123A58 →
#0C4A6E → #075985 → #0369A1 → #0284C7 → #0EA5E9 → #38BDF8 → #7DD3FC` (low recedes into the dark surface).

**Fixed series mapping** (colour follows the entity, never rank): questions answered `--chart-1`, refused
`--chart-2`, escalated `--chart-3`; incidents opened `--chart-1`, resolved `--chart-2`; single-series bars
(incidents by category, top documents) use `--chart-1` only. Severity/status colours are never chart series.

**Marks:** bars 4 px top radius, 2 px `--surface` gap between stacked segments and adjacent bars; lines
2 px, markers 8 px with 2 px `--surface` ring; grid 1 px `--chart-grid`, horizontal only; one y-axis (no
dual axes); legend always for ≥ 2 series, direct labels for ≤ 4; tooltip with crosshair on line/area and
per-bar hover, values in `--chart-tooltip-fg` text (never series colour for text); every chart has a
"Table" toggle rendering the same data as an accessible table; empty state per §7; loading skeleton the
size of the chart.

**Theming:** `useChartTheme()` reads the tokens with `getComputedStyle(document.documentElement)` on mount
and whenever the `dark` class on `<html>` or `prefers-color-scheme` changes (MutationObserver +
`matchMedia` listener) and re-registers the ECharts theme; fonts `Onest Variable`.

---

## 9. Telegram message style (worker templates)

- HTML only: `<b>` for headers and labels, `<i>` for hints, `<blockquote>` for quoted employee text,
  `<code>` for ids, `<a>` for links. No emoji, no stickers, no decorative symbols beyond `•`, `—`, `№`.
- Structure: bold title line → 1–4 short lines → sources block (numbered) → keyboard (max 4 buttons, one
  per row, verbs: «Оформить инцидент», «Передать менеджеру», «Взять в работу», «Готово» / "Create
  incident", "Pass to manager", "Take it", "Done").
- Manager answers always start with the bold label «Ответ менеджера (не из регламента)».

---

## 10. Accessibility baseline

4.5:1 text and 3:1 UI-component contrast in both themes (§2.1); visible focus on every interactive
element; full keyboard paths (board DnD has a menu alternative); labels on all inputs; `aria-label` on
icon buttons; `aria-live` for async status (bot connect, document processing, toasts); 44 px targets;
reduced motion honoured; no information by colour alone (icon + text on every badge); charts have
tables; `lang` attribute per locale; skip-to-content link; dialogs trap focus and restore it.

---

## 11. Anti-patterns (rejected)

Emoji or Telegram stickers anywhere · Heroicons or mixed icon sets · Inter or system-only typography ·
Unbounded below 24 px or in body text · hex/Tailwind palette literals in components (`bg-white`,
`#E2E8F0`, `#0080FF`, `#000000`, `#FFFACD`) · light-only screens or auto-inverted dark mode · gradient
backgrounds on data UI · thick 3–4 px clay borders · scale-on-hover that shifts layout · charts coloured
by rank, dual axes, rainbow scales, donuts with > 3 slices · spinners for page content · marketing-funnel
layouts on app screens · CDN fonts, scripts or images · token-by-token streaming (ADR-0017) · the
foundation collapsible sidebar or breadcrumbs, or any shell copied from PayFlow/ScamPause (§4.1) · "not
supported in your currency" copy (PLATFORM §11).

---

## 12. Pre-delivery checklist (every UI PR)

- [ ] Tokens only (grep: no `#[0-9a-f]{3,6}` and no `bg-white|text-black|gray-` in `resources/js/**`, except
      the documented QR exception).
- [ ] Fonts from `@fontsource-variable/*` index imports; no network font requests; Cyrillic renders in
      Unbounded/Onest (check «Регламенты» heading at 375 px).
- [ ] Lucide icons only; `simple-icons` only for brand marks; no emoji (lint rule / grep for emoji ranges).
- [ ] RU and EN strings via keys; Russian plurals checked (1/2/5/11/21/22).
- [ ] **Light and dark screenshots for every page and every chart** at 375 / 768 / 1440 in
      `e2e/artifacts/<page>/<locale>-<theme>-<width>.png`, reviewed side by side.
- [ ] Empty, loading and error states present and screenshotted.
- [ ] axe clean in both themes; keyboard-only walkthrough done; focus visible.
- [ ] `prefers-reduced-motion` emulated: no movement, spinners replaced by text.
- [ ] Touch targets ≥ 44 px; no horizontal scroll at 375 px.
- [ ] Charts: fixed series mapping, legend/direct labels, table toggle, tooltip, theme switch re-colours
      without reload.
- [ ] `data-test` hooks from the page spec present.
