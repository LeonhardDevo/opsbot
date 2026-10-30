<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Casts\PgIntegerArray;
use App\Domain\Opsbot\Access\Actor;
use App\Enums\IncidentCategory;
use App\Models\Workspace;
use App\Support\DbRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class AnalyticsQueries
{
    public function __construct(private QuestionGrouper $grouper) {}

    /** @return array{start: CarbonImmutable, end: CarbonImmutable, start_date: string, end_date: string} */
    public static function window(Workspace $workspace, int $period, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($workspace->zone());
        $start = $now->startOfDay()->subDays($period - 1);

        return [
            'start' => $start->utc(),
            'end' => $now->utc(),
            'start_date' => $start->toDateString(),
            'end_date' => $now->toDateString(),
        ];
    }

    /**
     * @return list<int>|null
     *
     * @throws OpsbotException
     */
    public static function departmentFilter(Actor $actor, ?int $departmentId): ?array
    {
        if ($actor->can('ai_queries.view_all') || $actor->privileged()) {
            return $departmentId !== null ? [$departmentId] : null;
        }

        if ($departmentId !== null) {
            if (! $actor->inDepartment($departmentId)) {
                throw OpsbotException::forbidden();
            }

            return [$departmentId];
        }

        return $actor->departmentIds === [] ? [0] : $actor->departmentIds;
    }

    /**
     * @param  list<int>|null  $departments
     * @return array<string, mixed>
     */
    public function compute(Workspace $workspace, int $period, ?array $departments, ?CarbonImmutable $now = null): array
    {
        $window = self::window($workspace, $period, $now);
        $tz = $workspace->zone();

        return [
            'kpis' => $this->kpis($workspace->id, $window, $departments),
            'series' => $this->series($workspace->id, $window, $departments, $tz),
            'topDocuments' => $this->topDocuments($workspace->id, $window, $departments),
            'topUnanswered' => $this->topUnanswered($workspace->id, $window, $departments),
            'neverCited' => $this->neverCited($workspace->id, $window['end']),
            'incidents' => $this->incidents($workspace->id, $window, $departments, $tz),
            'tasks' => $this->tasks($workspace->id, $window, $departments),
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, start_date: string, end_date: string}  $window
     * @param  list<int>|null  $departments
     * @return array{questions: int, answered_pct: float|null, refused_pct: float|null, escalated_pct: float|null, median_latency_ms: int|null, p90_latency_ms: int|null, active_askers: int}
     */
    public function kpis(int $workspaceId, array $window, ?array $departments): array
    {
        [$filter, $bindings] = $this->queryFilter($workspaceId, $window, $departments);

        $row = DB::selectOne(
            "SELECT count(*) AS total,
                    count(*) FILTER (WHERE NOT refused) AS answered,
                    count(*) FILTER (WHERE refused) AS refused,
                    count(*) FILTER (WHERE escalated_question_id IS NOT NULL) AS escalated,
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY latency_ms) FILTER (WHERE latency_ms IS NOT NULL) AS p50,
                    percentile_cont(0.9) WITHIN GROUP (ORDER BY latency_ms) FILTER (WHERE latency_ms IS NOT NULL) AS p90,
                    count(DISTINCT user_id) AS askers
             FROM ai_queries WHERE {$filter}",
            $bindings,
        );

        $total = (int) ($row->total ?? 0);

        return [
            'questions' => $total,
            'answered_pct' => self::pct((int) ($row->answered ?? 0), $total),
            'refused_pct' => self::pct((int) ($row->refused ?? 0), $total),
            'escalated_pct' => self::pct((int) ($row->escalated ?? 0), $total),
            'median_latency_ms' => isset($row->p50) ? (int) round((float) $row->p50) : null,
            'p90_latency_ms' => isset($row->p90) ? (int) round((float) $row->p90) : null,
            'active_askers' => (int) ($row->askers ?? 0),
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, start_date: string, end_date: string}  $window
     * @param  list<int>|null  $departments
     * @return list<array{date: string, total: int, answered: int, refused: int, escalated: int}>
     */
    public function series(int $workspaceId, array $window, ?array $departments, string $tz): array
    {
        [$filter, $bindings] = $this->queryFilter($workspaceId, $window, $departments);

        $rows = DB::select(
            "SELECT to_char(date_trunc('day', created_at AT TIME ZONE ?), 'YYYY-MM-DD') AS day,
                    count(*) AS total,
                    count(*) FILTER (WHERE NOT refused) AS answered,
                    count(*) FILTER (WHERE refused) AS refused,
                    count(*) FILTER (WHERE escalated_question_id IS NOT NULL) AS escalated
             FROM ai_queries WHERE {$filter} GROUP BY 1",
            [$tz, ...$bindings],
        );

        $byDay = [];

        foreach ($rows as $row) {
            $byDay[(string) $row->day] = $row;
        }

        $series = [];
        $date = CarbonImmutable::parse($window['start_date']);
        $end = $window['end_date'];

        while ($date->toDateString() <= $end) {
            $key = $date->toDateString();
            $row = $byDay[$key] ?? null;
            $series[] = [
                'date' => $key,
                'total' => (int) ($row->total ?? 0),
                'answered' => (int) ($row->answered ?? 0),
                'refused' => (int) ($row->refused ?? 0),
                'escalated' => (int) ($row->escalated ?? 0),
            ];
            $date = $date->addDay();
        }

        return $series;
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, start_date: string, end_date: string}  $window
     * @param  list<int>|null  $departments
     * @return list<array{document_id: int, title: string, citations: int}>
     */
    public function topDocuments(int $workspaceId, array $window, ?array $departments): array
    {
        [$filter, $bindings] = $this->queryFilter($workspaceId, $window, $departments, 'q');

        $rows = DB::select(
            "SELECT (v->>'document_id')::bigint AS document_id,
                    coalesce(d.title, max(v->>'title')) AS title,
                    count(*) AS citations
             FROM ai_queries q
             CROSS JOIN LATERAL jsonb_array_elements(q.cited_document_versions) AS v
             LEFT JOIN documents d ON d.id = (v->>'document_id')::bigint AND d.workspace_id = q.workspace_id
             WHERE {$filter}
             GROUP BY 1, d.title
             ORDER BY citations DESC, title ASC, document_id ASC
             LIMIT 10",
            $bindings,
        );

        return array_values(array_map(fn (mixed $row): array => [
            'document_id' => DbRow::int($row, 'document_id'),
            'title' => DbRow::string($row, 'title'),
            'citations' => DbRow::int($row, 'citations'),
        ], $rows));
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, start_date: string, end_date: string}  $window
     * @param  list<int>|null  $departments
     * @return list<array{representative: string, count: int, last_asked_at: string}>
     */
    public function topUnanswered(int $workspaceId, array $window, ?array $departments, int $limit = 10): array
    {
        [$filter, $bindings] = $this->queryFilter($workspaceId, $window, $departments);

        $rows = DB::select(
            "SELECT id, question, question_normalized, created_at FROM ai_queries
             WHERE {$filter} AND (refused OR escalated_question_id IS NOT NULL) AND question IS NOT NULL
             ORDER BY created_at DESC, id DESC LIMIT 2000",
            $bindings,
        );

        $groups = $this->grouper->group(array_values(array_map(fn (mixed $row): array => [
            'id' => DbRow::int($row, 'id'),
            'normalized' => DbRow::string($row, 'question_normalized'),
            'text' => DbRow::string($row, 'question'),
            'at' => CarbonImmutable::parse(DbRow::string($row, 'created_at'))->utc()->toIso8601String(),
        ], $rows)));

        return array_map(fn (array $group): array => [
            'representative' => $group['representative'],
            'count' => $group['count'],
            'last_asked_at' => $group['last_asked_at'],
        ], array_slice($groups, 0, $limit));
    }

    /** @return list<array{document_id: int, title: string, approved_at: string}> */
    public function neverCited(int $workspaceId, CarbonImmutable $now): array
    {
        $rows = DB::select(
            "SELECT d.id, d.title, d.approved_at FROM documents d
             WHERE d.workspace_id = ? AND d.approval_status = 'approved' AND d.archived_at IS NULL
               AND NOT EXISTS (
                 SELECT 1 FROM ai_queries q CROSS JOIN LATERAL jsonb_array_elements(q.cited_document_versions) AS v
                 WHERE q.workspace_id = d.workspace_id AND q.created_at >= ? AND (v->>'document_id')::bigint = d.id
               )
             ORDER BY d.approved_at ASC NULLS LAST, d.id ASC",
            [$workspaceId, $now->subDays(30)],
        );

        return array_values(array_map(fn (mixed $row): array => [
            'document_id' => DbRow::int($row, 'id'),
            'title' => DbRow::string($row, 'title'),
            'approved_at' => DbRow::get($row, 'approved_at') !== null ? CarbonImmutable::parse(DbRow::string($row, 'approved_at'))->utc()->toIso8601String() : '',
        ], $rows));
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, start_date: string, end_date: string}  $window
     * @param  list<int>|null  $departments
     * @return array{by_category: array<string, int>, by_week: list<array{week_start: string, opened: int, resolved: int}>, median_resolution_hours: float|null}
     */
    public function incidents(int $workspaceId, array $window, ?array $departments, string $tz): array
    {
        [$departmentSql, $departmentBindings] = $departments === null ? ['', []] : [' AND department_id = ANY(?::integer[])', [PgIntegerArray::format($departments)]];

        $byCategory = array_fill_keys(IncidentCategory::values(), 0);

        foreach (DB::select(
            "SELECT category, count(*) AS total FROM incidents
             WHERE workspace_id = ? AND created_at >= ? AND created_at <= ?{$departmentSql} GROUP BY category",
            [$workspaceId, $window['start'], $window['end'], ...$departmentBindings],
        ) as $row) {
            $byCategory[(string) $row->category] = (int) $row->total;
        }

        $weeks = [];
        $cursor = CarbonImmutable::parse($window['start_date'])->startOfWeek(CarbonImmutable::MONDAY);

        while ($cursor->toDateString() <= $window['end_date']) {
            $weeks[$cursor->toDateString()] = ['week_start' => $cursor->toDateString(), 'opened' => 0, 'resolved' => 0];
            $cursor = $cursor->addWeek();
        }

        foreach (DB::select(
            "SELECT to_char(date_trunc('week', created_at AT TIME ZONE ?), 'YYYY-MM-DD') AS week, count(*) AS total FROM incidents
             WHERE workspace_id = ? AND created_at >= ? AND created_at <= ?{$departmentSql} GROUP BY 1",
            [$tz, $workspaceId, $window['start'], $window['end'], ...$departmentBindings],
        ) as $row) {
            if (isset($weeks[(string) $row->week])) {
                $weeks[(string) $row->week]['opened'] = (int) $row->total;
            }
        }

        foreach (DB::select(
            "SELECT to_char(date_trunc('week', resolved_at AT TIME ZONE ?), 'YYYY-MM-DD') AS week, count(*) AS total FROM incidents
             WHERE workspace_id = ? AND resolved_at >= ? AND resolved_at <= ?{$departmentSql} GROUP BY 1",
            [$tz, $workspaceId, $window['start'], $window['end'], ...$departmentBindings],
        ) as $row) {
            if (isset($weeks[(string) $row->week])) {
                $weeks[(string) $row->week]['resolved'] = (int) $row->total;
            }
        }

        $median = DB::selectOne(
            "SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM resolved_at - created_at) / 3600) AS median
             FROM incidents WHERE workspace_id = ? AND resolved_at >= ? AND resolved_at <= ?{$departmentSql}",
            [$workspaceId, $window['start'], $window['end'], ...$departmentBindings],
        );

        return [
            'by_category' => $byCategory,
            'by_week' => array_values($weeks),
            'median_resolution_hours' => isset($median->median) ? round((float) $median->median, 1) : null,
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, start_date: string, end_date: string}  $window
     * @param  list<int>|null  $departments
     * @return array{created: int, completed: int, overdue_now: int, on_time_pct: float|null}
     */
    public function tasks(int $workspaceId, array $window, ?array $departments): array
    {
        [$departmentSql, $departmentBindings] = $departments === null ? ['', []] : [' AND department_id = ANY(?::integer[])', [PgIntegerArray::format($departments)]];

        $row = DB::selectOne(
            "SELECT
                count(*) FILTER (WHERE created_at >= ? AND created_at <= ?) AS created,
                count(*) FILTER (WHERE status = 'done' AND completed_at >= ? AND completed_at <= ?) AS completed,
                count(*) FILTER (WHERE status IN ('todo', 'in_progress') AND due_at < ?) AS overdue_now,
                count(*) FILTER (WHERE status = 'done' AND completed_at >= ? AND completed_at <= ? AND due_at IS NOT NULL) AS done_with_due,
                count(*) FILTER (WHERE status = 'done' AND completed_at >= ? AND completed_at <= ? AND due_at IS NOT NULL AND completed_at <= due_at) AS on_time
             FROM tasks WHERE workspace_id = ?{$departmentSql}",
            [
                $window['start'], $window['end'],
                $window['start'], $window['end'],
                $window['end'],
                $window['start'], $window['end'],
                $window['start'], $window['end'],
                $workspaceId, ...$departmentBindings,
            ],
        );

        return [
            'created' => (int) ($row->created ?? 0),
            'completed' => (int) ($row->completed ?? 0),
            'overdue_now' => (int) ($row->overdue_now ?? 0),
            'on_time_pct' => self::pct((int) ($row->on_time ?? 0), (int) ($row->done_with_due ?? 0)),
        ];
    }

    public static function pct(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total, 4) : null;
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, start_date: string, end_date: string}  $window
     * @param  list<int>|null  $departments
     * @return array{0: string, 1: list<mixed>}
     */
    private function queryFilter(int $workspaceId, array $window, ?array $departments, string $alias = ''): array
    {
        $prefix = $alias !== '' ? $alias.'.' : '';
        $sql = "{$prefix}workspace_id = ? AND {$prefix}created_at >= ? AND {$prefix}created_at <= ?";
        $bindings = [$workspaceId, $window['start'], $window['end']];

        if ($departments !== null) {
            $sql .= " AND {$prefix}department_ids_at_time && ?::integer[]";
            $bindings[] = PgIntegerArray::format($departments);
        }

        return [$sql, $bindings];
    }
}
