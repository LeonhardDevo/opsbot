<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\DepartmentScope;
use App\Domain\Opsbot\OpsbotException;
use App\Domain\Opsbot\Presenters\TaskPresenter;
use App\Domain\Opsbot\Support\DisplayNumber;
use App\Domain\Opsbot\TaskService;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Http\Requests\Opsbot\DueAt;
use App\Models\Incident;
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

final class TasksController extends OpsController
{
    public function index(Request $request, DepartmentScope $scope): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'tasks.view_own');

        $filters = $request->validate([
            'view' => ['nullable', 'string', Rule::in(['board', 'table'])],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(TaskStatus::values())],
            'assignee' => ['nullable', 'string', 'regex:/^(me|[0-9]+)$/'],
            'department_id' => ['nullable', 'integer'],
            'due' => ['nullable', 'string', Rule::in(['overdue', 'today', 'week'])],
            'focus' => ['nullable', 'integer'],
        ]);

        $view = $filters['view'] ?? 'board';
        unset($filters['view'], $filters['focus']);
        $now = CarbonImmutable::now();

        $base = fn (): Builder => $this->filtered($scope->tasks(Task::query()->withoutGlobalScopes(), $actor), $filters, $actor, $now);

        $page = $base()->with(TaskPresenter::relations())->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('id')->paginate(25)->withQueryString();
        $board = [];
        $counts = [];

        foreach (TaskStatus::cases() as $status) {
            $board[$status->value] = $base()->with(TaskPresenter::relations())
                ->where('status', $status->value)
                ->orderByRaw('due_at IS NULL')
                ->orderBy('due_at')
                ->orderByDesc('id')
                ->limit((int) config('opsbot.board_column_limit', 50))
                ->get()
                ->map(fn (Task $task): array => TaskPresenter::present($task, $now))
                ->values()
                ->all();
            $counts[$status->value] = $base()->where('status', $status->value)->count();
        }

        $counts['overdue'] = $base()->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])->where('due_at', '<', $now)->count();

        return Inertia::render('tasks/Index', [
            'view' => $view,
            'tasks' => Paginated::from($page, fn (Task $task): array => TaskPresenter::present($task, $now)),
            'board' => $board,
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            'counts' => $counts,
            'departments' => $this->departmentRefs($actor->workspace),
            'assignees' => $this->assignableUsers($actor->workspace, 'tasks.view_own'),
            'can' => [
                'create' => $actor->can('tasks.create') && $actor->role->atLeast(WorkspaceRole::Manager),
                'manage' => $actor->can('tasks.manage'),
            ],
        ]);
    }

    public function store(Request $request, TaskService $tasks, DepartmentScope $scope): RedirectResponse
    {
        $actor = $this->actor();

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'incident_id' => ['nullable', 'integer'],
        ]);

        $incident = null;

        if (isset($data['incident_id'])) {
            $incident = Incident::query()->forWorkspace($actor->workspace)->find((int) $data['incident_id']);

            if ($incident === null || ! $scope->canViewIncident($actor, $incident)) {
                throw OpsbotException::notFound();
            }
        }

        $task = $tasks->create($actor, [
            'title' => (string) $data['title'],
            'description' => $data['description'] ?? null,
            'due_at' => DueAt::parse($data['due_at'] ?? null, $actor->workspace->zone()),
            'assignee_id' => isset($data['assignee_id']) ? (int) $data['assignee_id'] : null,
            'department_id' => isset($data['department_id']) ? (int) $data['department_id'] : null,
        ], $incident !== null ? TaskSource::Incident : TaskSource::Web, $incident);

        Flash::success('flash.tasks.created', ['number' => DisplayNumber::task($task->number)]);

        return back();
    }

    public function update(Request $request, Task $task, TaskService $tasks): RedirectResponse
    {
        $actor = $this->actor();

        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(TaskStatus::class)],
            'title' => ['sometimes', 'string', 'min:3', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $payload = [];

        foreach (['status', 'title'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = (string) $data[$key];
            }
        }

        if (array_key_exists('description', $data)) {
            $payload['description'] = $data['description'];
        }

        if (array_key_exists('due_at', $data)) {
            $payload['due_at'] = DueAt::parse($data['due_at'], $actor->workspace->zone());
        }

        if (array_key_exists('assignee_id', $data)) {
            $payload['assignee_id'] = $data['assignee_id'] !== null ? (int) $data['assignee_id'] : null;
        }

        $tasks->update($actor, $task, $payload);
        Flash::success('flash.tasks.updated');

        return back();
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Task>
     */
    private function filtered(Builder $query, array $filters, Actor $actor, CarbonImmutable $now): Builder
    {
        $local = $now->setTimezone($actor->workspace->zone());

        return $query
            ->when($filters['q'] ?? null, fn (Builder $query, string $q) => $query->where(function (Builder $inner) use ($q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $inner->where('tasks.title', 'ilike', $like)->orWhere('tasks.description', 'ilike', $like);
            }))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('tasks.status', $status))
            ->when($filters['assignee'] ?? null, fn (Builder $query, string $assignee) => $query->where('tasks.assignee_id', $assignee === 'me' ? $actor->id() : (int) $assignee))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int|string $department) => $query->where('tasks.department_id', (int) $department))
            ->when(($filters['due'] ?? null) === 'overdue', fn (Builder $query) => $query->whereIn('tasks.status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])->where('tasks.due_at', '<', $now))
            ->when(($filters['due'] ?? null) === 'today', fn (Builder $query) => $query->whereBetween('tasks.due_at', [$local->startOfDay()->utc(), $local->endOfDay()->utc()]))
            ->when(($filters['due'] ?? null) === 'week', fn (Builder $query) => $query->whereBetween('tasks.due_at', [$local->startOfDay()->utc(), $local->addDays(7)->endOfDay()->utc()]));
    }
}
