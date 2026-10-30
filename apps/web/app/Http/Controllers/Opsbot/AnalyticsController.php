<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Billing\PlanCatalog;
use App\Domain\Opsbot\AnalyticsQueries;
use App\Enums\IncidentCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AnalyticsController extends OpsController
{
    public function __invoke(Request $request, AnalyticsQueries $analytics, PlanCatalog $catalog): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'analytics.view');

        $data = $request->validate([
            'period' => ['nullable', 'integer', Rule::in([7, 30, 90])],
            'department_id' => ['nullable', 'integer'],
        ]);

        $period = (int) ($data['period'] ?? 30);
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;
        $workspace = $actor->workspace;
        $locked = ! $catalog->hasFeature($workspace->planKey(), 'analytics');

        $base = [
            'locked' => $locked,
            'period' => $period,
            'departmentId' => $departmentId,
            'timezone' => $workspace->zone(),
            'departments' => $actor->privileged()
                ? $this->departmentRefs($workspace)
                : array_values(array_filter($this->departmentRefs($workspace), fn (array $department): bool => $actor->inDepartment($department['id']))),
        ];

        if ($locked) {
            return Inertia::render('analytics/Index', [
                ...$base,
                'kpis' => ['questions' => 0, 'answered_pct' => null, 'refused_pct' => null, 'escalated_pct' => null, 'median_latency_ms' => null, 'p90_latency_ms' => null, 'active_askers' => 0],
                'series' => [],
                'topDocuments' => [],
                'topUnanswered' => [],
                'neverCited' => [],
                'incidents' => ['by_category' => array_fill_keys(IncidentCategory::values(), 0), 'by_week' => [], 'median_resolution_hours' => null],
                'tasks' => ['created' => 0, 'completed' => 0, 'overdue_now' => 0, 'on_time_pct' => null],
            ]);
        }

        $departments = AnalyticsQueries::departmentFilter($actor, $departmentId);
        $key = 'analytics:'.$workspace->id.':'.$period.':'.$workspace->zone().':'.implode(',', $departments ?? ['all']);

        $result = Cache::remember($key, (int) config('opsbot.analytics_cache_seconds', 300), fn (): array => $analytics->compute($workspace, $period, $departments));

        return Inertia::render('analytics/Index', [...$base, ...$result]);
    }
}
