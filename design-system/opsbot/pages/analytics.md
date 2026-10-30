# Analytics — screen spec

Route `/analytics?period=7|30|90&department_id=` · page `analytics/Index` · ability `analytics.view`
(managers: own departments; plan feature `analytics`, free → upgrade card) · props `AnalyticsProps`
(contract §6.2) · definitions contract §13, ADR-0016.

## Purpose
Answer three questions for owners and managers: is the bot useful (answered vs refused vs escalated,
latency)? what is missing from the knowledge base (top unanswered, never-cited documents)? how are
operations going (incidents, tasks)?

## Layout
- **Header**: H1 «Аналитика»; period segmented control (7 / 30 / 90 дней); department select (owners and
  admins; managers see only their departments); caption «Часовой пояс: Europe/Moscow».
- **KPI row** (6 tiles): вопросов, отвечено %, отказов %, передано менеджеру %, медиана ответа
  (ms → «1,2 с»), активных сотрудников. Percentages `null` → «—».
- **Questions over time** (12 cols): stacked bar per day, series answered `--chart-1`, refused
  `--chart-2`, escalated `--chart-3` (fixed mapping, MASTER §8), legend + direct labels on the last bar,
  crosshair tooltip with all three values and the total, "Таблица" toggle.
- **Knowledge gaps** (two cards, 6 + 6): «Частые вопросы без ответа» (ranked list: representative text,
  count, last asked, link → `/unanswered` filtered) and «Документы без цитирований за 30 дней» (list with
  approval date and link; caption «Возможно, их стоит переписать или архивировать»).
- **Top cited documents** (6 cols): horizontal single-series bar chart (`--chart-1`), document titles as
  axis labels (wrap 2 lines, `overflow-wrap:anywhere`), values at bar ends, table toggle.
- **Incidents** (6 cols): «По категориям» horizontal bar (single series `--chart-1`, category icons in the
  table view) and «Открыто / решено по неделям» grouped bars (`--chart-1` opened, `--chart-2` resolved);
  caption with median resolution hours.
- **Tasks** (12 cols): 4 KPI mini tiles (created, completed, overdue now (coral when > 0), on-time %).

## States
Empty period: each chart shows the empty state («За этот период вопросов не было»), KPIs show 0 / «—».
Loading: skeletons sized as the charts. Error per card with retry. Free plan (`locked: true`): a static upgrade card
explaining what analytics shows (an SVG sketch of the charts, no fake numbers and no blurred real data).

## Charts (MASTER §8)
`useChartTheme()` for both themes; one y-axis; 4 px bar radius, 2 px gaps; tooltips use tooltip tokens;
every chart has a table alternative; screenshots of each chart in light and dark are part of the PR.

## data-test
`analytics-period` (+ `data-period`), `analytics-department`, `analytics-kpi` (+ `data-kpi`),
`analytics-questions-chart`, `analytics-table-toggle`, `analytics-top-unanswered`,
`analytics-never-cited`, `analytics-top-documents`, `analytics-incidents-category`,
`analytics-incidents-weekly`, `analytics-tasks`, `analytics-upgrade`.
