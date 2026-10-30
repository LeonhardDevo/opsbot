# Assistant (web bot preview) — screen spec

Routes `/assistant` (`assistant/Index`), `POST /assistant/ask` → `ChatAnswer`, `POST
/assistant/actions/{action}` → `ActionResult` · ability `assistant.use` · contract §5, §6.2.

## Purpose
Let web users (and demos/E2E) talk to the assistant exactly as employees do in Telegram: same answers,
sources, confidence, proposed actions with confirmation. **No streaming** (ADR-0017).

## Layout
Two panes at ≥ 1024: chat (8 cols, max-width 760 px for the message column) + context panel (4 cols).
Single pane below 1024 (context panel becomes a "Источники" sheet opened from the answer).
- **Header**: H1 «Ассистент» + caption «Так сотрудники видят ответы в Telegram» + link "Открыть бота в
  Telegram" (`simple-icons` Telegram mark + `@username`, when a company bot is active).
- **Empty conversation**: illustration (chat bubbles + document sheet + `BookOpenText` glyph), Display M
  title «Спросите о любом регламенте», 4 suggestion chips from `suggestions` (radius 999, secondary).
- **Message list**: `ChatBubble` variants (MASTER §7). Bot bubble contents in order: answer (sanitized
  HTML, max 6 sentences or a numbered list), compensation disclaimer line in italics when present, citation
  chips row, `ConfidenceMeter` (shows band word; medium adds caption «Проверьте источник»), action buttons
  (max 4) from `actions`. Refused answer: coral left bar, fixed refusal text, "Передать менеджеру" button.
  Executed actions show a system bubble "Инцидент № 12 создан" with a link.
- **Composer** (sticky bottom): textarea auto-grow (1–6 lines, max 4000 chars with counter after 3500),
  Enter sends, Shift+Enter newline, send icon button (`SendHorizontal`, `aria-label`), disabled while
  waiting; typing indicator bubble while waiting (static text under reduced motion).
- **Context panel**: "Источники ответа" — for the selected bot message: list of citations (title,
  version, effective date, section, snippet with `--mark` highlight, open link); "Как оценена
  уверенность" explainer (threshold value from settings); limits caption "Вопросов в этом месяце: 1 240 из
  15 000".

## Action confirmation
Clicking "Оформить инцидент" / "Создать задачу" calls `step:'preview'` and opens a dialog rendering
`ActionResult.card` (title + label/value list) with "Подтвердить" (primary) and "Отмена"; confirm calls
`step:'confirm'`; result toast + system bubble. Double click protection: button disabled while pending;
server idempotency covers the rest.

## States
Loading answer: typing bubble. Error (ai unavailable): bot bubble in neutral with
«Сервис ответов временно недоступен» + retry. Limit exhausted / subscription paused: composer replaced by
a callout with the reason and (for admins) an upgrade/billing link. Notice: none (web users accepted terms).

## a11y
Message list `role="log"` with `aria-live="polite"`; each bot message is an `article` with an accessible
name "Ответ ассистента"; citations are links; focus returns to the composer after sending.

## data-test
`assistant-suggestion`, `assistant-input`, `assistant-send`, `assistant-message` (+ `data-role=
employee|bot|manager|system`, `data-refused`), `assistant-citation`, `assistant-confidence`,
`assistant-action` (+ `data-action-type`), `assistant-action-confirm`, `assistant-sources-panel`.
