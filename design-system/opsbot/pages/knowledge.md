# Knowledge (documents) — screen spec

Routes `/knowledge` (`knowledge/Index`) and `/knowledge/{document}` (`knowledge/Show`) · abilities
`knowledge.view` / `knowledge.manage` / `knowledge.approve` · props contract §5, `KbDocument` §6.2.

## Purpose
Upload, process, review and **approve** regulations; keep them current (review dates, supersede); see
what the bot can and cannot answer from.

## Index layout
- **Header**: H1 «Регламенты и инструкции» / "Regulations"; actions: "Загрузить" (primary, `Upload`),
  "Стартовый набор" (secondary, opens the starter-pack dialog when not imported).
- **Limits bar** (caption row): documents used/limit, storage used/limit, pages per document limit.
- **Tabs with counts** (segmented on mobile): Все · Черновики (`counts.draft`) · Утверждённые ·
  Архив · Пора пересмотреть (`counts.review_overdue`, sun).
- **Filter row**: search (`Search`), processing status select, department select, reset (tabs drive the
  `approval` / `review` filters; all filters live in the query string).
- **Table** columns: checkbox (approve-bulk; only drafts that are indexed), title (+ original file name
  caption, `ScanText` icon when OCR used), departments chips (or «Все отделы»), roles chips (or «Все
  роли»), version (mono), processing badge (live progress "OCR 12 / 150"), approval badge, effective
  date (future → primary "с 22 нояб."), review due (overdue → sun badge), cited in 30 days (number),
  row menu (Open, Edit metadata, Approve, Archive, Retry, Delete — per permissions and state).
  Below 768: card per document with the two badges on top.
- **Bulk bar** (appears when rows are selected): "Утвердить выбранные (N)" → dialog with effective date
  (default today) and the list of titles.
- Polling every 3 s while any row is `queued|processing` (stop when none; pause when tab hidden).

## Upload dialog
Dropzone (drag & drop + browse; accepted types from `accept`; max size from limits; multiple files queue
with per-file progress), fields: title (prefilled from file name), departments multi-select, roles
multi-select (owner/admin/manager/employee; empty = everyone), tags (chips input), version, language
(ru/en/auto), "Заменяет документ" (searchable select of approved docs → `supersedes_document_id`).
Helper text: «Документ попадёт в черновики. Бот начнёт отвечать по нему после утверждения.»

## Starter-pack dialog
Pack card (title, description, 8 document titles), languages checkboxes, "create departments" switch,
warning callout «Это шаблоны — адаптируйте их под свою компанию перед утверждением», primary "Импортировать".

## Show layout
- Header: title (H1), badges (processing, approval, review), actions per permission: Approve (primary;
  dialog with effective date + review date, defaults today / +12 months, and supersede info), Archive
  (destructive confirm), Edit metadata (sheet), Download (signed), Delete (drafts/archived).
- Left (8 cols): **Test a question** panel (`knowledge.approve`; input + "Проверить" → hits with
  confidence bars and snippets with `--mark`; caption explains that drafts are never shown to employees),
  then **Chunks preview** (section path, page, content; first 20; "Показать ещё").
- Right (4 cols): metadata list (departments, roles, tags, language, version, uploaded by/at, approved
  by/at, effective date, review due), **Versions** timeline (supersedes / superseded by with dates and
  status), **Cited recently** (last 10 questions, scoped by permission; "Просмотр журнала фиксируется").

## States
Empty index: illustration (stack of document sheets with a check badge) + «Добавьте первые регламенты» +
Upload + Starter pack buttons. Empty filtered: «Ничего не найдено» + reset. Failed processing row: coral
badge, error text by `error_code` (localized), Retry. Loading: table skeleton (8 rows).

## data-test
`knowledge-upload`, `knowledge-upload-file`, `knowledge-upload-submit`, `knowledge-starter-pack`,
`knowledge-pack-import`, `knowledge-tab` (+ `data-tab`), `knowledge-row` (+ `data-document-id`,
`data-approval`, `data-status`), `knowledge-row-select`, `knowledge-bulk-approve`, `knowledge-approve`,
`knowledge-approve-confirm`, `knowledge-archive`, `knowledge-test-input`, `knowledge-test-submit`,
`knowledge-test-hit`, `knowledge-versions`.
