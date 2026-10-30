# Departments & routing — screen spec

Route `/departments` · page `departments/Index` · ability `departments.manage` · props contract §5
(`departments`, `rules`, `categories`, `managers`); actions POST/PATCH/DELETE `/departments…`,
PUT `/departments/routing`.

## Purpose
Define departments (used for document visibility and manager scope) and decide **who receives what**:
incident categories, escalated questions and employee-proposed tasks per department.

## Layout
- **Header**: H1 «Отделы и маршрутизация»; action "Добавить отдел" (primary, `Plus`).
- **Departments grid** (cards, 3 columns at 1440, 2 at 768, 1 at 375): name (H2), code (mono caption),
  manager (avatar initials circle + name, or «Не назначен» sun chip), counts (`Users` members,
  `FileText` documents), menu (Edit, Archive). Archived departments in a collapsed "Архив" section.
- **Routing matrix** card: rows = «Любой отдел» + each department; columns = routing categories
  (Повреждение, Жалоба, Нет доступа, Травма, Пропажа, Другое, Вопросы, Задачи) with category icons in the
  header. Cell = compact select of managers (owners/admins/managers) with an empty option «По умолчанию».
  Below 1024 the matrix becomes an accordion per department with a list of category → manager selects.
- **Fallback explainer** (info callout, `Route`): «Если правило не задано: правило для любого отдела →
  руководитель отдела → владелец пространства.» Each empty cell shows the resolved fallback name in
  `--muted-fg` italics (computed client-side from the «any department» row, `department.manager` and
  `fallbackOwner`) so admins see the effective routing.
- Sticky save bar appears when the matrix is dirty: "Сохранить маршрутизацию" (primary) + "Отменить".

## States
Empty departments: illustration (building blocks + route line) + «Создайте отделы, чтобы разделить
доступ к регламентам и маршрутизировать инциденты». Validation: archived departments cannot receive rules.

## a11y
Matrix is a real `<table>` with row/column headers; each select has an accessible name «Повреждение —
Уборка квартир».

## data-test
`departments-add`, `department-card` (+ `data-code`), `department-form-name`, `department-form-manager`,
`department-form-submit`, `department-archive`, `routing-matrix`, `routing-cell` (+ `data-department`,
`data-category`), `routing-save`.
