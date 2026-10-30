# Employees — screen spec

Route `/employees` · page `employees/Index` · ability `employees.manage` (managers: own departments) ·
props contract §5 (`employees: Paginated<Employee>`, `filters`, `departments`, `seats`, `bot`,
`pendingRelink`); actions POST/PATCH/DELETE `/employees…`, `/employees/{user}/link`,
`/employees/links/bulk`, `/employees/{user}/reactivate`.

## Purpose
Add field staff who have no e-mail, hand them a QR/deep link to the company bot, see who is connected,
and offboard people cleanly. Web users (managers/admins) are listed too but invited via `/settings/members`.

## Layout
- **Header**: H1 «Сотрудники» + caption; actions "Добавить сотрудника" (primary, `UserPlus`), "Ссылки для
  печати" (secondary, `Printer`, enabled when rows selected), "Пригласить менеджера" (ghost → members).
- **Seat bar**: "Места: 18 из 25" with progress (sun ≥ 80 %, coral at 100 % + upgrade link).
- **Banners**: no active company bot → info callout linking `/bot` (links will use the platform bot or
  cannot be issued); `pendingRelink > 0` → sun callout «N сотрудникам нужна новая ссылка» + "Выдать
  всем" (bulk links).
- **Filters**: search (name/phone/position), department select, status (invited/active/offboarded),
  Telegram (not linked/linked/blocked).
- **Table**: select, name (+ position caption; "Вы" chip for self), kind chip (Telegram / Веб), role
  badge, departments chips (primary department first, `Star` icon marks primary), Telegram status
  (MASTER component) with `@username`, link state (active until … / expired), last question (relative),
  open tasks count, status badge, row menu (Edit, New link, Offboard / Reactivate). Mobile: cards.

## Create / edit sheet (right side, full screen on mobile)
Fields: full name, position (optional), phone (optional, E.164 hint, masked in the table), departments
multi-select + primary department radio among selected, language (RU/EN), role (edit only, owner/admin,
employee ↔ manager requires a web account → disabled with explanation). Submit → on create, the **link
dialog** opens automatically.

## Link dialog
`EmployeeQrCard` (MASTER §7): QR (always dark on white), deep link in a read-only mono field + Copy
(`Copy`, toast «Ссылка скопирована»), expiry countdown ("действует 7 дней, до 29 окт."), "Распечатать"
(print stylesheet: name, company, QR, 3 short steps «Откройте камеру → Нажмите Start → Прочитайте
уведомление»), "Новая ссылка" (revokes the old one, confirm). Shown once; closing it does not reveal the
token again (a new link must be issued).

## Bulk print
Selected employees (≤ 50) → `/employees/links/bulk` → printable sheet: 2 × 3 cards per A4 page.

## Offboard
Destructive confirm: «Отключить Айгуль Сапарову? Доступ к боту будет закрыт, открытые задачи перейдут
менеджеру отдела.» Shows count of open tasks to be reassigned.

## Data requests (row menu, ADR-0015)
- «Выгрузить данные» / "Export data" (`Download`, `employees.manage`): confirm dialog listing what the ZIP
  contains → toast «Выгрузка готовится — ссылка придёт на почту и в уведомления (24 ч)».
- «Удалить персональные данные» / "Erase personal data" (`UserX`, owner/admin, only for `offboarded`):
  destructive dialog with password re-confirmation; text: name, phone and Telegram link are deleted,
  questions are cleared, incidents and tasks stay with «Бывший сотрудник №<id>»; irreversible; type the
  employee's name to confirm. Erased rows show the pseudonym and a muted «данные удалены» badge.

## States
Empty: illustration (QR card + chat bubble + person silhouette made of circles) + «Добавьте первых
сотрудников — им не нужна почта, только Telegram» + primary. Seat limit reached: create button disabled
with tooltip + upgrade link. Loading: table skeleton.

## data-test
`employees-add`, `employees-seat-bar`, `employees-row` (+ `data-user-id`, `data-telegram-state`,
`data-status`), `employee-form-name`, `employee-form-departments`, `employee-form-primary`,
`employee-form-submit`, `employee-link-dialog`, `employee-link-url`, `employee-link-copy`,
`employee-link-qr`, `employee-link-new`, `employees-bulk-links`, `employee-offboard`,
`employee-offboard-confirm`, `employee-reactivate`, `employee-export`, `employee-export-confirm`,
`employee-erase`, `employee-erase-password`, `employee-erase-confirm`.
