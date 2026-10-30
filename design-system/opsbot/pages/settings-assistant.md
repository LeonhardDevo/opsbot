# Settings → Assistant — screen spec

Route `/settings/assistant` · page `settings/Assistant` · ability `settings.assistant` · props
`{ settings: OpsSettings, refusalRate30d }` (contract §5, §6.2) · PATCH same route · ADR-0006, ADR-0013.

## Purpose
Let owners/admins tune how cautious the bot is and when people may be pinged.

## Layout (settings layout from the starter kit, content max-width 720 px)
1. **Answer confidence** card: H2 «Порог уверенности»; explanation «Если уверенность ниже порога, бот не
   отвечает, а предлагает передать вопрос менеджеру»; slider 0.20–0.80 step 0.05 with value label
   («0.40 — по умолчанию» tick), two end captions «Отвечает чаще» / «Осторожнее»; live caption with
   `refusalRate30d` («За 30 дней бот отказал в 12 % вопросов»; «—» when null). Changes are audited.
2. **Quiet hours** card: two time inputs (start, end, 24 h, workspace timezone caption), switch
   "Напоминания о задачах" (`reminders_enabled`); example timeline (24 h bar, quiet range shaded
   `--surface-2` with `Moon` icon) updating live.
3. **Data & AI providers** link card (no data of its own): «Разрешить внешние ИИ-провайдеры и сроки
   хранения настраиваются в разделе Конфиденциальность» → `/settings/privacy` (F2). No duplication of the
   platform AI admin page.
4. Sticky save bar when dirty: "Сохранить" (primary) + "Отменить".

## Validation
Threshold within 0.20–0.80; quiet hours start ≠ end (overnight ranges allowed, e.g. 21:00–08:00).
Errors localized inline.

## States
Loading skeleton cards; saved toast «Настройки сохранены».

## data-test
`assistant-settings-threshold`, `assistant-settings-refusal-rate`, `assistant-settings-quiet-start`,
`assistant-settings-quiet-end`, `assistant-settings-reminders`, `assistant-settings-save`.
