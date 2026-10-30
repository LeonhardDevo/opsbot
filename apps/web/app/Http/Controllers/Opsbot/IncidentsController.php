<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\DepartmentScope;
use App\Domain\Opsbot\IncidentService;
use App\Domain\Opsbot\OpsbotException;
use App\Domain\Opsbot\Presenters\IncidentPresenter;
use App\Domain\Opsbot\Presenters\TaskPresenter;
use App\Domain\Opsbot\Support\DisplayNumber;
use App\Domain\Opsbot\TaskService;
use App\Enums\IncidentCategory;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Enums\TaskSource;
use App\Http\Requests\Opsbot\DueAt;
use App\Models\AiQuery;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Task;
use App\Support\Flash;
use App\Support\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class IncidentsController extends OpsController
{
    public function index(Request $request, DepartmentScope $scope): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'incidents.view_own');

        $filters = $request->validate([
            'view' => ['nullable', 'string', Rule::in(['board', 'table'])],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(IncidentStatus::values())],
            'category' => ['nullable', 'string', Rule::in(IncidentCategory::values())],
            'severity' => ['nullable', 'string', Rule::in(Severity::values())],
            'department_id' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'regex:/^(me|[0-9]+)$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $view = $filters['view'] ?? 'board';
        unset($filters['view']);

        $base = fn (): Builder => $this->filtered($scope->incidents(Incident::query()->withoutGlobalScopes(), $actor), $filters, $actor);
        $now = CarbonImmutable::now();

        $page = $base()->with(IncidentPresenter::relations())->withCount('tasks')->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();

        $board = [];
        $counts = [];

        foreach (IncidentStatus::cases() as $status) {
            $board[$status->value] = $base()->with(IncidentPresenter::relations())->withCount('tasks')
                ->where('status', $status->value)
                ->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
                ->orderByDesc('created_at')
                ->limit((int) config('opsbot.board_column_limit', 50))
                ->get()
                ->map(fn (Incident $incident): array => IncidentPresenter::present($incident, $now))
                ->values()
                ->all();
            $counts[$status->value] = $base()->where('status', $status->value)->count();
        }

        return Inertia::render('incidents/Index', [
            'view' => $view,
            'incidents' => Paginated::from($page, fn (Incident $incident): array => IncidentPresenter::present($incident, $now)),
            'board' => $board,
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            'counts' => $counts,
            'departments' => $this->departmentRefs($actor->workspace),
            'assignees' => $this->assignableUsers($actor->workspace, 'incidents.manage'),
            'can' => ['create' => $actor->can('incidents.create'), 'manage' => $actor->can('incidents.manage')],
        ]);
    }

    public function store(Request $request, IncidentService $incidents): RedirectResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'incidents.create');

        $data = $request->validate([
            'category' => ['required', 'string', Rule::enum(IncidentCategory::class)],
            'severity' => ['nullable', 'string', Rule::enum(Severity::class)],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'client_ref' => ['nullable', 'string', 'max:120'],
            'department_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
        ]);

        $incident = $incidents->create($actor, [
            'category' => (string) $data['category'],
            'severity' => $data['severity'] ?? null,
            'description' => (string) $data['description'],
            'client_ref' => $data['client_ref'] ?? null,
            'department_id' => isset($data['department_id']) ? (int) $data['department_id'] : null,
            'assignee_id' => isset($data['assignee_id']) ? (int) $data['assignee_id'] : null,
        ], IncidentSource::Web);

        Flash::success('flash.incidents.created', ['number' => DisplayNumber::incident($incident->number)]);

        return redirect()->route('incidents.show', ['incident' => $incident->id]);
    }

    public function show(Incident $incident, DepartmentScope $scope, IncidentService $incidents, TaskService $tasks): Response
    {
        $actor = $this->actor();

        if (! $scope->canViewIncident($actor, $incident)) {
            throw OpsbotException::notFound();
        }

        $incident->load(IncidentPresenter::relations())->loadCount('tasks');
        $query = $incident->ai_query_id !== null ? AiQuery::query()->forWorkspace($actor->workspace)->find($incident->ai_query_id) : null;

        return Inertia::render('incidents/Show', [
            'incident' => IncidentPresenter::present($incident),
            'events' => IncidentEvent::query()->forWorkspace($actor->workspace)->where('incident_id', $incident->id)->with('actor')->orderBy('id')->get()
                ->map(fn (IncidentEvent $event): array => IncidentPresenter::event($event))->values()->all(),
            'tasks' => Task::query()->forWorkspace($actor->workspace)->where('incident_id', $incident->id)->with(TaskPresenter::relations())->orderBy('id')->get()
                ->map(fn (Task $task): array => TaskPresenter::present($task))->values()->all(),
            'sourceQuery' => $query !== null && $scope->canViewAiQuery($actor, $query) ? [
                'id' => $query->id,
                'question' => (string) $query->question,
                'answer' => $query->answer,
                'citations' => array_map(fn (array $citation, int $index): array => [
                    'index' => $index + 1,
                    'document_id' => (int) ($citation['document_id'] ?? 0),
                    'title' => (string) ($citation['title'] ?? ''),
                    'page' => isset($citation['page']) ? (int) $citation['page'] : null,
                    'chunk_id' => (int) ($citation['chunk_id'] ?? 0),
                    'snippet' => (string) ($citation['snippet'] ?? ''),
                    'version' => isset($citation['version']) ? (string) $citation['version'] : null,
                    'url' => $actor->can('knowledge.view') ? route('knowledge.show', ['document' => (int) ($citation['document_id'] ?? 0)], false) : null,
                ], $query->citations, array_keys($query->citations)),
            ] : null,
            'assignees' => $this->assignableUsers($actor->workspace, 'incidents.create'),
            'transitions' => array_map(fn (IncidentStatus $status): string => $status->value, $incidents->transitionsFor($actor, $incident)),
            'can' => [
                'manage' => $scope->canManageIncident($actor, $incident),
                'comment' => true,
                'createTask' => $actor->can('tasks.manage') && $scope->canManageIncident($actor, $incident),
            ],
        ]);
    }

    public function update(Request $request, Incident $incident, IncidentService $incidents): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(IncidentStatus::class)],
            'severity' => ['sometimes', 'nullable', 'string', Rule::enum(Severity::class)],
            'category' => ['sometimes', 'nullable', 'string', Rule::enum(IncidentCategory::class)],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $payload = array_filter([
            'status' => $data['status'] ?? null,
            'severity' => $data['severity'] ?? null,
            'category' => $data['category'] ?? null,
            'comment' => $data['comment'] ?? null,
        ], fn (mixed $value): bool => $value !== null);

        if (array_key_exists('assignee_id', $data)) {
            $payload['assignee_id'] = $data['assignee_id'] !== null ? (int) $data['assignee_id'] : null;
        }

        $incidents->update($this->actor(), $incident, $payload);
        Flash::success('flash.incidents.updated');

        return back();
    }

    public function comment(Request $request, Incident $incident, IncidentService $incidents): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:2000']]);

        $incidents->comment($this->actor(), $incident, (string) $data['body']);
        Flash::success('flash.incidents.commented');

        return back();
    }

    public function storeTask(Request $request, Incident $incident, DepartmentScope $scope, TaskService $tasks): RedirectResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'tasks.manage');

        if (! $scope->canManageIncident($actor, $incident)) {
            throw OpsbotException::forbidden();
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'due_at' => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'integer'],
        ]);

        $task = $tasks->create($actor, [
            'title' => (string) $data['title'],
            'due_at' => DueAt::parse($data['due_at'] ?? null, $actor->workspace->zone()),
            'assignee_id' => isset($data['assignee_id']) ? (int) $data['assignee_id'] : null,
            'department_id' => $incident->department_id,
        ], TaskSource::Incident, $incident);

        Flash::success('flash.tasks.created', ['number' => DisplayNumber::task($task->number)]);

        return back();
    }

    /**
     * @param  Builder<Incident>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Incident>
     */
    private function filtered(Builder $query, array $filters, Actor $actor): Builder
    {
        $tz = $actor->workspace->zone();

        return $query
            ->when($filters['q'] ?? null, fn (Builder $query, string $q) => $query->where(function (Builder $inner) use ($q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $inner->where('incidents.title', 'ilike', $like)->orWhere('incidents.description', 'ilike', $like)->orWhere('incidents.client_ref', 'ilike', $like);

                if (ctype_digit(ltrim($q, '#№ '))) {
                    $inner->orWhere('incidents.number', (int) ltrim($q, '#№ '));
                }
            }))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('incidents.status', $status))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('incidents.category', $category))
            ->when($filters['severity'] ?? null, fn (Builder $query, string $severity) => $query->where('incidents.severity', $severity))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int|string $department) => $query->where('incidents.department_id', (int) $department))
            ->when($filters['assignee'] ?? null, fn (Builder $query, string $assignee) => $query->where('incidents.assignee_id', $assignee === 'me' ? $actor->id() : (int) $assignee))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('incidents.created_at', '>=', CarbonImmutable::parse($from, $tz)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('incidents.created_at', '<=', CarbonImmutable::parse($to, $tz)->endOfDay()->utc()));
    }
}
