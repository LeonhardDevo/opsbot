# Document viewer (signed source link) — screen spec

Routes `/s/doc/{document}` (`share/DocumentViewer`, `share/LinkError`) · no session, signed URL
(ADR-0010) · props contract §5. Opened from Telegram on a phone in most cases.

## Purpose
Show the employee the exact place in the approved regulation the bot quoted, quickly and readably, in
their language and theme, without an account.

## Layout (mobile-first)
Minimal shell: no sidebar, no app header. Top bar 56 px: `AppLogoMark` (small) + workspace name caption;
right: theme toggle (light/dark/system, persisted per device) — locale comes from the user, a small
RU/EN switch is still provided.
Content column max-width 760 px, padding 16 (mobile) / 32:
1. Title (H1, wraps, hyphenated), chips: version (mono), «Действует с 1 янв. 2026» (`CalendarClock`),
   departments (or «Все отделы»).
2. **Excerpt card** (radius 20): section path caption (e.g. «Что делать, если вы поцарапали…»), page
   caption; `before` paragraph in `--muted-fg`, the cited `content` wrapped in `<mark>` styled with
   `--mark` background + `--fg` text + 2 px left bar `--highlight-sun-strong`, `after` paragraph muted.
   Font 17 px / 1.65 for readability on phones.
3. "Скачать оригинал" (secondary, `Download`) — signed 5-minute link.
4. Footer caption: «Ссылка действует 30 минут и доступна только вам. Просмотр фиксируется в журнале
   компании.» + link to the employee notice.

**Archived state** (`state = 'archived'`): sun callout «Этот регламент заменён» + successor link (if
visible) instead of the excerpt highlight; the old excerpt is shown collapsed and muted.

## LinkError page
Centered card with illustration (clock + broken link geometric SVG), title by reason:
expired «Ссылка устарела — спросите бота ещё раз», forbidden «У вас нет доступа к этому документу»,
not_found «Документ не найден». No details that leak document titles for `forbidden`.

## Rules
No external requests; `noindex`; works with JavaScript disabled for the content (server-rendered Inertia
first paint acceptable; the excerpt must be in the initial HTML). Print stylesheet prints title + excerpt.

## a11y
`<mark>` has a visually hidden prefix «Цитата из ответа бота:»; contrast `--fg` on `--mark` 13.98 / 7.35.

## data-test
`viewer-title`, `viewer-version`, `viewer-excerpt`, `viewer-mark`, `viewer-download`,
`viewer-successor`, `viewer-error` (+ `data-reason`).
