<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Opsbot\AnalyticsQueries;
use App\Domain\Opsbot\DepartmentScope;
use App\Domain\Opsbot\DeskFeed;
use App\Domain\Opsbot\LimitNotices;
use App\Domain\Opsbot\OnboardingService;
use App\Domain\Opsbot\Presenters\BotPresenter;
use App\Domain\Opsbot\Presenters\IncidentPresenter;
use App\Domain\Opsbot\Presenters\QuestionPresenter;
use App\Domain\Opsbot\Presenters\TaskPresenter;
use App\Enums\ApprovalStatus;
use App\Enums\IncidentStatus;
use App\Enums\QuestionStatus;
use App\Enums\Severity;
use App\Enums\TaskStatus;
use App\Http\Controllers\Opsbot\OpsController;
use App\Models\Document;
use App\Models\Incident;
use App\Models\Task;
use App\Models\TelegramBot;
use App\Models\UnansweredQuestion;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends OpsController
{
    public function __invoke(
        DepartmentScope $scope,
        DeskFeed $feed,
        AnalyticsQueries $analytics,
        OnboardingService $onboarding,
        LimitNotices $limits,
    ): Response {
        $actor = $this->actor();
        $workspace = $actor->workspace;
        $now = CarbonImmutable::now();
        $manager = $actor->can('incidents.manage');

        $departments = $manager ? AnalyticsQueries::departmentFilter($actor, null) : null;
        $window7 = AnalyticsQueries::window($workspace, 7, $now);
        $previous = [
            'start' => $window7['start']->subDays(7),
            'end' => $window7['start'],
            'start_date' => CarbonImmutable::parse($window7['start_date'])->subDays(7)->toDateString(),
            'end_date' => CarbonImmutable::parse($window7['start_date'])->subDay()->toDateString(),
        ];

        $kpisNow = $manager ? $analytics->kpis($workspace->id, $window7, $departments) : null;
        $kpisPrev = $manager ? $analytics->kpis($workspace->id, $previous, $departments) : null;
        $badges = $feed->badges($actor);

        $severity = [];

        foreach (Severity::cases() as $level) {
            $base = $scope->incidents(Incident::query()->withoutGlobalScopes(), $actor)
                ->whereIn('status', [IncidentStatus::Open->value, IncidentStatus::InProgress->value])
                ->where('severity', $level->value);
            $severity[$level->value] = [
                'open' => (clone $base)->count(),
                'unassigned' => (clone $base)->whereNull('assignee_id')->count(),
            ];
        }

        $series = $manager
            ? array_map(fn (array $day): array => [
                'date' => $day['date'],
                'answered' => $day['answered'],
                'refused' => $day['refused'],
                'escalated' => $day['escalated'],
            ], $analytics->series($workspace->id, AnalyticsQueries::window($workspace, 14, $now), $departments, $workspace->zone()))
            : [];

        $reviewQuery = Document::query()
            ->forWorkspace($workspace)
            ->where('approval_status', ApprovalStatus::Approved->value)
            ->whereNotNull('review_due_at')
            ->whereDate('review_due_at', '<=', $workspace->today());

        return Inertia::render('Dashboard', [
            'today' => $feed->today($actor, (int) config('opsbot.dashboard_today_limit', 20)),
            'kpis' => [
                'questions_7d' => [
                    'value' => $kpisNow['questions'] ?? 0,
                    'delta' => $kpisNow !== null && $kpisPrev !== null && $kpisPrev['questions'] > 0
                        ? round(($kpisNow['questions'] - $kpisPrev['questions']) / $kpisPrev['questions'], 4)
                        : null,
                ],
                'answered_rate_7d' => $kpisNow['answered_pct'] ?? null,
                'open_incidents' => $badges['incidents_open'],
                'overdue_tasks' => $badges['tasks_overdue'],
                'unanswered_open' => $badges['unanswered_open'],
            ],
            'incidentsBySeverity' => $severity,
            'questionsSeries' => $series,
            'recentIncidents' => $scope->incidents(Incident::query()->withoutGlobalScopes(), $actor)
                ->with(IncidentPresenter::relations())->withCount('tasks')
                ->orderByDesc('created_at')->limit(5)->get()
                ->map(fn (Incident $incident): array => IncidentPresenter::present($incident, $now))->values()->all(),
            'myTasks' => Task::query()->forWorkspace($workspace)
                ->where('assignee_id', $actor->id())
                ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
                ->with(TaskPresenter::relations())
                ->orderByRaw('due_at IS NULL')->orderBy('due_at')->limit(5)->get()
                ->map(fn (Task $task): array => TaskPresenter::present($task, $now))->values()->all(),
            'unanswered' => $actor->can('questions.answer')
                ? $scope->questions(UnansweredQuestion::query()->forWorkspace($workspace), $actor)
                    ->where('status', QuestionStatus::Open->value)
                    ->with(QuestionPresenter::relations())
                    ->orderBy('created_at')->limit(5)->get()
                    ->map(fn (UnansweredQuestion $question): array => QuestionPresenter::present($question, 0, $now))->values()->all()
                : [],
            'bot' => $actor->can('bot.manage')
                ? BotPresenter::present(TelegramBot::query()->with('connector')->where('workspace_id', $workspace->id)->first())
                : null,
            'reviewDue' => [
                'count' => $actor->can('knowledge.approve') ? (clone $reviewQuery)->count() : 0,
                'top' => $actor->can('knowledge.approve')
                    ? (clone $reviewQuery)->orderBy('review_due_at')->limit(3)->get()
                        ->map(fn (Document $document): array => [
                            'id' => $document->id,
                            'title' => $document->title,
                            'review_due_at' => (string) $document->review_due_at?->toDateString(),
                        ])->values()->all()
                    : [],
            ],
            'onboarding' => $onboarding->pending($workspace, $actor->role) ? ['steps' => $onboarding->steps($workspace)] : null,
            'limitsWarning' => $actor->privileged() ? $limits->warnings($workspace) : [],
        ]);
    }
}
