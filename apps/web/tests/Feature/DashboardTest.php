<?php

declare(strict_types=1);

use App\Enums\AiChannel;
use App\Enums\BotStatus;
use App\Enums\IncidentCategory;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\QuestionReason;
use App\Enums\QuestionStatus;
use App\Enums\Severity;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiQuery;
use App\Models\Incident;
use App\Models\Task;
use App\Models\TelegramBot;
use App\Models\UnansweredQuestion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/** @param array<string, mixed> $attributes */
function dashboardQuery(Workspace $workspace, array $attributes = []): AiQuery
{
    $query = new AiQuery;
    $query->forceFill([
        'workspace_id' => $workspace->id,
        'channel' => AiChannel::Web,
        'question' => 'How do I report damage?',
        'answer' => 'Stop work [1]',
        'refused' => false,
        'confidence' => 0.82,
        'citations' => [],
        'tools_used' => [],
        ...$attributes,
    ])->save();

    return $query;
}

/** @param array<string, mixed> $attributes */
function dashboardIncident(Workspace $workspace, User $reporter, array $attributes = []): Incident
{
    static $number = 0;
    $number++;

    $incident = new Incident;
    $incident->forceFill([
        'workspace_id' => $workspace->id,
        'number' => $number,
        'category' => IncidentCategory::Damage,
        'severity' => Severity::Medium,
        'status' => IncidentStatus::Open,
        'title' => 'Incident '.$number,
        'description' => 'Something happened',
        'reporter_id' => $reporter->id,
        'source' => IncidentSource::Web,
        ...$attributes,
    ])->save();

    return $incident;
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('orders the today feed by urgency and keeps it inside the workspace', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $other = Workspace::factory()->create();
    [$stranger] = $this->member(WorkspaceRole::Owner, $other);

    $assigned = dashboardIncident($workspace, $employee, ['assignee_id' => $owner->id, 'title' => 'Assigned medium']);
    $unassigned = dashboardIncident($workspace, $employee, ['title' => 'Unassigned medium', 'created_at' => now()->subHour()]);
    $high = dashboardIncident($workspace, $employee, ['severity' => Severity::High, 'assignee_id' => $owner->id, 'title' => 'Injury']);
    dashboardIncident($other, $stranger, ['severity' => Severity::High, 'title' => 'Foreign']);

    $query = dashboardQuery($workspace, ['user_id' => $employee->id, 'refused' => true]);
    (new UnansweredQuestion)->forceFill([
        'workspace_id' => $workspace->id, 'number' => 1, 'ai_query_id' => $query->id, 'question' => 'Old question',
        'employee_user_id' => $employee->id, 'reason' => QuestionReason::NotFound, 'status' => QuestionStatus::Open,
        'created_at' => now()->subHours(30),
    ])->save();

    Task::query()->create([
        'workspace_id' => $workspace->id, 'number' => 1, 'title' => 'Late task', 'status' => TaskStatus::Todo,
        'due_at' => now()->subHours(3), 'assignee_id' => $employee->id, 'created_by' => $owner->id, 'source' => TaskSource::Web,
    ]);

    TelegramBot::query()->create([
        'workspace_id' => $workspace->id, 'tg_bot_id' => 7001, 'username' => 'acme_bot', 'status' => BotStatus::Revoked,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard', false)
            ->has('today', 5)
            ->where('today.0.id', 'incident:'.$high->id)
            ->where('today.0.severity', 'high')
            ->where('today.0.actions', ['take', 'open'])
            ->where('today.1.id', 'incident:'.$unassigned->id)
            ->where('today.1.actions', ['take', 'open'])
            ->where('today.2.kind', 'question')
            ->where('today.3.kind', 'task_overdue')
            ->where('today.4.kind', 'bot')
            ->where('kpis.open_incidents', 3)
            ->where('kpis.overdue_tasks', 1)
            ->where('kpis.unanswered_open', 1)
            ->where('incidentsBySeverity', [
                'low' => ['open' => 0, 'unassigned' => 0],
                'medium' => ['open' => 2, 'unassigned' => 1],
                'high' => ['open' => 1, 'unassigned' => 0],
            ])
            ->has('recentIncidents', 3)
            ->has('unanswered', 1));

    expect($assigned->exists)->toBeTrue();
});

it('returns fourteen days of questions in the workspace timezone with a weekly delta', function (): void {
    Carbon::setTestNow('2026-10-22 21:30:00');
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $workspace->forceFill(['timezone' => 'Europe/Moscow'])->save();

    dashboardQuery($workspace, ['created_at' => Carbon::parse('2026-10-22 21:10:00', 'UTC')]);
    dashboardQuery($workspace, ['created_at' => Carbon::parse('2026-10-22 08:00:00', 'UTC'), 'refused' => true]);
    dashboardQuery($workspace, ['created_at' => Carbon::parse('2026-10-14 08:00:00', 'UTC')]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('questionsSeries', 14)
            ->where('questionsSeries.0.date', '2026-10-10')
            ->where('questionsSeries.12', ['date' => '2026-10-22', 'answered' => 0, 'refused' => 1, 'escalated' => 0])
            ->where('questionsSeries.13', ['date' => '2026-10-23', 'answered' => 1, 'refused' => 0, 'escalated' => 0])
            ->where('kpis.questions_7d', ['value' => 2, 'delta' => 1])
            ->where('kpis.answered_rate_7d', 0.5));
});

it('shows employees only their own items and no management widgets', function (): void {
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    [$employee] = $this->signInMember(WorkspaceRole::Employee, $workspace);
    dashboardIncident($workspace, $owner, ['severity' => Severity::High]);
    $own = dashboardIncident($workspace, $employee, ['severity' => Severity::High]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('today', 1)
            ->where('today.0.id', 'incident:'.$own->id)
            ->where('kpis.questions_7d', ['value' => 0, 'delta' => null])
            ->where('kpis.open_incidents', 0)
            ->where('questionsSeries', [])
            ->where('bot', null)
            ->where('onboarding', null)
            ->where('limitsWarning', [])
            ->where('badges', ['unanswered_open' => 0, 'incidents_open' => 0, 'tasks_overdue' => 0]));
});

it('warns owners about limits at 80 percent', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    config(['billing.plans.free.limits.seats' => 1]);

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('limitsWarning', [['key' => 'seats', 'used' => 1, 'limit' => 1]]));

    expect($workspace->exists)->toBeTrue();
});

it('serves the live desk feed after a cursor', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $old = dashboardIncident($workspace, $owner, ['updated_at' => now()->subHours(2), 'created_at' => now()->subHours(2)]);
    $new = dashboardIncident($workspace, $owner);

    $response = $this->getJson(route('desk.feed', ['after' => now()->subHour()->toIso8601String()]))->assertOk();

    expect(collect($response->json('items'))->pluck('id')->all())->toBe(['incident:'.$new->id])
        ->and($response->json('cursor'))->toBe($new->updated_at->toIso8601String());

    $all = $this->getJson(route('desk.feed'))->assertOk();
    expect(collect($all->json('items'))->pluck('id')->all())->toContain('incident:'.$old->id);
});
