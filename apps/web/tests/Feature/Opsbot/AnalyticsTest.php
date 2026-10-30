<?php

declare(strict_types=1);

use App\Domain\Opsbot\AnalyticsQueries;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\Subscription;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function proPlan(Workspace $workspace): void
{
    Subscription::query()->create(['workspace_id' => $workspace->id, 'plan' => 'pro', 'status' => SubscriptionStatus::Active]);
}

it('computes exact KPIs and day buckets in the workspace timezone', function (string $timezone, array $expectedSeries): void {
    CarbonImmutable::setTestNow('2026-10-22 12:00:00');
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    $workspace->forceFill(['timezone' => $timezone])->save();
    $document = Document::factory()->approved()->create(['workspace_id' => $workspace->id, 'title' => 'Damage']);
    $versions = [['document_id' => $document->id, 'title' => 'Damage', 'version' => null, 'approval_status' => 'approved', 'effective_date' => '2026-01-01']];

    $this->aiQuery($workspace, $employee, ['refused' => false, 'latency_ms' => 100, 'created_at' => '2026-10-22 02:00:00', 'cited_document_versions' => $versions]);
    $this->aiQuery($workspace, $employee, ['refused' => false, 'latency_ms' => 300, 'created_at' => '2026-10-21 22:30:00', 'cited_document_versions' => $versions]);
    $escalated = $this->aiQuery($workspace, $employee, ['refused' => true, 'latency_ms' => 500, 'created_at' => '2026-10-21 10:00:00']);
    $question = $this->question($workspace, $employee, ['ai_query_id' => $escalated->id]);
    $escalated->forceFill(['escalated_question_id' => $question->id])->save();
    [$other] = $this->member(WorkspaceRole::Employee, $workspace);
    $this->aiQuery($workspace, $other, ['refused' => true, 'latency_ms' => null, 'created_at' => '2026-10-20 23:30:00']);
    $this->aiQuery($workspace, $other, ['refused' => false, 'created_at' => '2026-09-01 10:00:00']);

    $result = app(AnalyticsQueries::class)->compute($workspace, 7, null);

    expect($result['kpis'])->toBe([
        'questions' => 4,
        'answered_pct' => 0.5,
        'refused_pct' => 0.5,
        'escalated_pct' => 0.25,
        'median_latency_ms' => 300,
        'p90_latency_ms' => 460,
        'active_askers' => 2,
    ])
        ->and(array_slice($result['series'], -3))->toBe($expectedSeries)
        ->and($result['series'])->toHaveCount(7)
        ->and($result['topDocuments'])->toBe([['document_id' => $document->id, 'title' => 'Damage', 'citations' => 2]])
        ->and($result['neverCited'])->toBe([]);
})->with([
    'Moscow' => ['Europe/Moscow', [
        ['date' => '2026-10-20', 'total' => 0, 'answered' => 0, 'refused' => 0, 'escalated' => 0],
        ['date' => '2026-10-21', 'total' => 2, 'answered' => 0, 'refused' => 2, 'escalated' => 1],
        ['date' => '2026-10-22', 'total' => 2, 'answered' => 2, 'refused' => 0, 'escalated' => 0],
    ]],
    'New York' => ['America/New_York', [
        ['date' => '2026-10-20', 'total' => 1, 'answered' => 0, 'refused' => 1, 'escalated' => 0],
        ['date' => '2026-10-21', 'total' => 3, 'answered' => 2, 'refused' => 1, 'escalated' => 1],
        ['date' => '2026-10-22', 'total' => 0, 'answered' => 0, 'refused' => 0, 'escalated' => 0],
    ]],
]);

it('returns zeros and nulls for an empty period', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);

    $result = app(AnalyticsQueries::class)->compute($workspace, 30, null);

    expect($result['kpis'])->toBe([
        'questions' => 0, 'answered_pct' => null, 'refused_pct' => null, 'escalated_pct' => null,
        'median_latency_ms' => null, 'p90_latency_ms' => null, 'active_askers' => 0,
    ])
        ->and($result['series'])->toHaveCount(30)
        ->and($result['topUnanswered'])->toBe([])
        ->and($result['incidents']['median_resolution_hours'])->toBeNull()
        ->and($result['incidents']['by_category'])->toBe(array_fill_keys(IncidentCategory::values(), 0))
        ->and($result['tasks'])->toBe(['created' => 0, 'completed' => 0, 'overdue_now' => 0, 'on_time_pct' => null]);
});

it('groups unanswered questions deterministically', function (): void {
    CarbonImmutable::setTestNow('2026-10-22 12:00:00');
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);

    foreach (['What is the Wi-Fi password?', 'what is the wifi password', 'WHAT IS THE WI-FI PASSWORD', 'How many vacation days?', 'Vacation days per year?'] as $index => $text) {
        $this->aiQuery($workspace, $employee, ['question' => $text, 'created_at' => now()->subHours($index + 1)]);
    }

    $window = AnalyticsQueries::window($workspace, 7);
    $first = app(AnalyticsQueries::class)->topUnanswered($workspace->id, $window, null);
    $second = app(AnalyticsQueries::class)->topUnanswered($workspace->id, $window, null);

    expect($first)->toBe($second)
        ->and(array_column($first, 'count'))->toBe([3, 1, 1])
        ->and($first[0]['representative'])->toBe('WHAT IS THE WI-FI PASSWORD')
        ->and(array_column($first, 'representative'))->toBe(['WHAT IS THE WI-FI PASSWORD', 'How many vacation days?', 'Vacation days per year?']);
});

it('computes incident and task metrics', function (): void {
    CarbonImmutable::setTestNow('2026-10-22 12:00:00');
    [, $workspace] = $this->member(WorkspaceRole::Owner);

    $this->incident($workspace, ['category' => IncidentCategory::Injury, 'status' => IncidentStatus::Resolved, 'created_at' => '2026-10-20 08:00:00', 'resolved_at' => '2026-10-20 12:00:00']);
    $this->incident($workspace, ['category' => IncidentCategory::Damage, 'status' => IncidentStatus::Resolved, 'created_at' => '2026-10-21 08:00:00', 'resolved_at' => '2026-10-21 18:00:00']);
    $this->incident($workspace, ['category' => IncidentCategory::Damage, 'created_at' => '2026-10-22 08:00:00']);

    $this->task($workspace, ['status' => TaskStatus::Done, 'due_at' => '2026-10-21 12:00:00', 'completed_at' => '2026-10-21 10:00:00', 'created_at' => '2026-10-20 10:00:00']);
    $this->task($workspace, ['status' => TaskStatus::Done, 'due_at' => '2026-10-21 12:00:00', 'completed_at' => '2026-10-21 14:00:00', 'created_at' => '2026-10-20 10:00:00']);
    $this->task($workspace, ['status' => TaskStatus::Todo, 'due_at' => '2026-10-21 12:00:00', 'created_at' => '2026-10-20 10:00:00']);

    $result = app(AnalyticsQueries::class)->compute($workspace, 7, null);

    expect($result['incidents']['by_category']['damage'])->toBe(2)
        ->and($result['incidents']['by_category']['injury'])->toBe(1)
        ->and($result['incidents']['median_resolution_hours'])->toBe(7.0)
        ->and(collect($result['incidents']['by_week'])->sum('opened'))->toBe(3)
        ->and(collect($result['incidents']['by_week'])->sum('resolved'))->toBe(2)
        ->and($result['tasks'])->toBe(['created' => 3, 'completed' => 2, 'overdue_now' => 1, 'on_time_pct' => 0.5]);
});

it('restricts managers to their departments and locks the page on the free plan', function (): void {
    Cache::flush();
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $mine = $this->department($workspace, 'mine');
    $other = $this->department($workspace, 'other');
    $this->inDepartment($manager, $mine);
    $this->aiQuery($workspace, $employee, ['department_ids_at_time' => [$mine->id]]);
    $this->aiQuery($workspace, $employee, ['department_ids_at_time' => [$other->id]]);

    $this->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('analytics/Index', false)->where('locked', true)->where('kpis.questions', 0)->where('series', []));

    proPlan($workspace);

    $this->get(route('analytics.index', ['period' => 7]))
        ->assertInertia(fn ($page) => $page
            ->where('locked', false)
            ->where('period', 7)
            ->where('timezone', 'UTC')
            ->where('kpis.questions', 1)
            ->has('departments', 1));

    $this->get(route('analytics.index', ['department_id' => $other->id]))->assertForbidden();
    $this->get(route('analytics.index', ['period' => 14]))->assertSessionHasErrors('period');
});

it('forbids employees from analytics', function (): void {
    $this->signInMember(WorkspaceRole::Employee);

    $this->get(route('analytics.index'))->assertForbidden();
});
