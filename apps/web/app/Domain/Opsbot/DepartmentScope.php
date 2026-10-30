<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Casts\PgIntegerArray;
use App\Domain\Opsbot\Access\Actor;
use App\Models\AiQuery;
use App\Models\DepartmentMember;
use App\Models\Incident;
use App\Models\Task;
use App\Models\UnansweredQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DepartmentScope
{
    /** @var array<string, list<int>> */
    private array $cache = [];

    /** @return list<int> */
    public function departmentIds(User $user, int $workspaceId): array
    {
        $key = $workspaceId.':'.$user->id;

        if (! array_key_exists($key, $this->cache)) {
            $ids = DepartmentMember::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $user->id)
                ->orderBy('department_id')
                ->pluck('department_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $this->cache[$key] = array_values($ids);
        }

        return $this->cache[$key];
    }

    public function primaryDepartmentId(User $user, int $workspaceId): ?int
    {
        $primary = DepartmentMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->orderByDesc('is_primary')
            ->orderBy('department_id')
            ->value('department_id');

        return $primary !== null ? (int) $primary : null;
    }

    public function forget(?User $user = null, ?int $workspaceId = null): void
    {
        if ($user === null || $workspaceId === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$workspaceId.':'.$user->id]);
    }

    /**
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function incidents(Builder $query, Actor $actor): Builder
    {
        return $this->apply($query, $actor, 'incidents', 'department_id', ['reporter_id', 'assignee_id']);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function tasks(Builder $query, Actor $actor): Builder
    {
        return $this->apply($query, $actor, 'tasks', 'department_id', ['assignee_id', 'created_by']);
    }

    /**
     * @param  Builder<UnansweredQuestion>  $query
     * @return Builder<UnansweredQuestion>
     */
    public function questions(Builder $query, Actor $actor): Builder
    {
        $table = $query->getModel()->getTable();

        if ($actor->privileged()) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($actor, $table): void {
            $inner->where($table.'.assignee_user_id', $actor->id())
                ->orWhere($table.'.employee_user_id', $actor->id());

            if ($actor->can('questions.answer') && $actor->departmentIds !== []) {
                $inner->orWhereIn($table.'.department_id', $actor->departmentIds);
            }
        });
    }

    /**
     * @param  Builder<AiQuery>  $query
     * @return Builder<AiQuery>
     */
    public function aiQueries(Builder $query, Actor $actor): Builder
    {
        $table = $query->getModel()->getTable();

        if ($actor->can('ai_queries.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($actor, $table): void {
            $inner->where($table.'.user_id', $actor->id());

            if ($actor->can('ai_queries.view_department') && $actor->departmentIds !== []) {
                $inner->orWhereRaw('ai_queries.department_ids_at_time && ?::integer[]', [PgIntegerArray::format($actor->departmentIds)]);
            }
        });
    }

    public function aiQueryScope(Actor $actor): string
    {
        return match (true) {
            $actor->can('ai_queries.view_all') => 'all',
            $actor->can('ai_queries.view_department') => 'department',
            default => 'self',
        };
    }

    public function canViewIncident(Actor $actor, Incident $incident): bool
    {
        return $this->canView($actor, 'incidents', $incident->workspace_id, $incident->department_id, [$incident->reporter_id, $incident->assignee_id]);
    }

    public function canManageIncident(Actor $actor, Incident $incident): bool
    {
        return $incident->workspace_id === $actor->workspaceId()
            && $actor->can('incidents.manage')
            && ($actor->can('incidents.view_all') || $actor->inDepartment($incident->department_id) || $incident->assignee_id === $actor->id());
    }

    public function canViewTask(Actor $actor, Task $task): bool
    {
        return $this->canView($actor, 'tasks', $task->workspace_id, $task->department_id, [$task->assignee_id, $task->created_by]);
    }

    public function canManageTask(Actor $actor, Task $task): bool
    {
        return $task->workspace_id === $actor->workspaceId()
            && $actor->can('tasks.manage')
            && ($actor->can('tasks.view_all') || $actor->inDepartment($task->department_id) || $task->created_by === $actor->id());
    }

    public function canViewQuestion(Actor $actor, UnansweredQuestion $question): bool
    {
        if ($question->workspace_id !== $actor->workspaceId()) {
            return false;
        }

        return $actor->privileged()
            || $question->assignee_user_id === $actor->id()
            || $question->employee_user_id === $actor->id()
            || ($actor->can('questions.answer') && $actor->inDepartment($question->department_id));
    }

    public function canAnswerQuestion(Actor $actor, UnansweredQuestion $question): bool
    {
        if ($question->workspace_id !== $actor->workspaceId() || ! $actor->can('questions.answer')) {
            return false;
        }

        return $actor->privileged()
            || $question->assignee_user_id === $actor->id()
            || $actor->inDepartment($question->department_id);
    }

    public function canViewAiQuery(Actor $actor, AiQuery $query): bool
    {
        if ($query->workspace_id !== $actor->workspaceId()) {
            return false;
        }

        return $actor->can('ai_queries.view_all')
            || $query->user_id === $actor->id()
            || ($actor->can('ai_queries.view_department') && $actor->intersects($query->department_ids_at_time));
    }

    public function canManageEmployee(Actor $actor, User $employee, ?int $workspaceId = null): bool
    {
        if (! $actor->can('employees.manage') || $actor->id() === $employee->id) {
            return false;
        }

        $targetRole = $employee->membershipIn($workspaceId ?? $actor->workspaceId())?->role;

        if ($targetRole === null || ! $actor->role->canManage($targetRole)) {
            return false;
        }

        if ($actor->privileged()) {
            return true;
        }

        return $actor->intersects($this->departmentIds($employee, $workspaceId ?? $actor->workspaceId()));
    }

    /** @param  list<int|null>  $own */
    private function canView(Actor $actor, string $prefix, int $workspaceId, ?int $departmentId, array $own): bool
    {
        if ($workspaceId !== $actor->workspaceId()) {
            return false;
        }

        if ($actor->can($prefix.'.view_all')) {
            return true;
        }

        if (in_array($actor->id(), $own, true)) {
            return true;
        }

        return $actor->can($prefix.'.view_department') && $actor->inDepartment($departmentId);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $ownColumns
     * @return Builder<TModel>
     */
    private function apply(Builder $query, Actor $actor, string $prefix, string $departmentColumn, array $ownColumns): Builder
    {
        $table = $query->getModel()->getTable();
        $query->where($table.'.workspace_id', $actor->workspaceId());

        if ($actor->can($prefix.'.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($actor, $prefix, $departmentColumn, $ownColumns, $table): void {
            foreach ($ownColumns as $column) {
                $inner->orWhere($table.'.'.$column, $actor->id());
            }

            if ($actor->can($prefix.'.view_department') && $actor->departmentIds !== []) {
                $inner->orWhereIn($table.'.'.$departmentColumn, $actor->departmentIds);
            }
        });
    }
}
