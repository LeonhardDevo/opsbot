<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Audit\Auditor;
use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Notifications\OpsNotifier;
use App\Domain\Opsbot\Support\DisplayNumber;
use App\Domain\Opsbot\Support\LocalTime;
use App\Domain\Opsbot\Support\WorkspaceCounters;
use App\Enums\IncidentEventType;
use App\Enums\MemberStatus;
use App\Enums\ReminderStatus;
use App\Enums\RoutingCategory;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\Department;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Outbox\Topics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TaskService
{
    public function __construct(
        private WorkspaceCounters $counters,
        private DepartmentScope $scope,
        private RoutingResolver $routing,
        private Auditor $auditor,
        private OpsNotifier $notifier,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, due_at?: CarbonImmutable|null, assignee_id?: int|null, department_id?: int|null}  $data
     *
     * @throws OpsbotException
     */
    public function create(
        Actor $actor,
        array $data,
        TaskSource $source,
        ?Incident $incident = null,
        ?int $aiQueryId = null,
        string $auditSource = Auditor::SOURCE_WEB,
    ): Task {
        if (! $actor->can('tasks.create')) {
            throw OpsbotException::forbidden();
        }

        $byRouting = ! $actor->role->atLeast(WorkspaceRole::Manager) || $source === TaskSource::AiProposal;

        if ($source === TaskSource::Web && $byRouting) {
            throw OpsbotException::forbidden('tasks.create_forbidden');
        }

        $departmentId = $data['department_id'] ?? $incident->department_id ?? $this->scope->primaryDepartmentId($actor->user, $actor->workspaceId());
        $this->assertDepartment($actor, $departmentId, ! $byRouting);

        if ($byRouting) {
            $assignee = $this->routing->resolve($actor->workspace, RoutingCategory::Task, $departmentId)['user'];
        } else {
            $assignee = isset($data['assignee_id']) ? $this->activeMember($actor->workspace, (int) $data['assignee_id']) : null;
        }

        return DB::transaction(function () use ($actor, $data, $source, $incident, $aiQueryId, $departmentId, $assignee, $auditSource): Task {
            $task = new Task;
            $task->fill([
                'workspace_id' => $actor->workspaceId(),
                'number' => $this->counters->next($actor->workspaceId(), WorkspaceCounters::TASK),
                'title' => Str::limit(trim($data['title']), 160, ''),
                'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
                'status' => TaskStatus::Todo,
                'due_at' => isset($data['due_at']) ? $data['due_at']->utc() : null,
                'assignee_id' => $assignee?->id,
                'created_by' => $actor->id(),
                'department_id' => $departmentId,
                'incident_id' => $incident?->id,
                'ai_query_id' => $aiQueryId,
                'source' => $source,
            ]);
            $task->save();

            $this->replan($task, true);

            if ($incident !== null) {
                IncidentEvent::query()->create([
                    'workspace_id' => $incident->workspace_id,
                    'incident_id' => $incident->id,
                    'actor_id' => $actor->id(),
                    'type' => IncidentEventType::TaskLinked,
                    'to_value' => (string) $task->number,
                ]);
                $incident->touch();
            }

            $this->auditor->record(
                'task.created',
                $task,
                metadata: array_filter([
                    'assignee_id' => $task->assignee_id,
                    'incident_id' => $task->incident_id,
                    'ai_query_id' => $aiQueryId,
                ], fn (mixed $value): bool => $value !== null),
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: $task->title,
                source: $auditSource,
            );

            if ($assignee !== null && $assignee->id !== $actor->id()) {
                $this->notifyAssigned($task, $assignee, $actor->user);
            }

            return $task;
        });
    }

    /**
     * @param  array{status?: string, title?: string, description?: string|null, due_at?: CarbonImmutable|null, assignee_id?: int|null}  $data
     *
     * @throws OpsbotException
     */
    public function update(Actor $actor, Task $task, array $data, string $auditSource = Auditor::SOURCE_WEB): Task
    {
        if (! $this->scope->canViewTask($actor, $task)) {
            throw OpsbotException::notFound();
        }

        $editing = array_intersect_key($data, array_flip(['title', 'description', 'due_at', 'assignee_id']));

        if ($editing !== [] && ! $this->scope->canManageTask($actor, $task)) {
            throw OpsbotException::forbidden();
        }

        return DB::transaction(function () use ($actor, $task, $data, $editing, $auditSource): Task {
            $task = Task::query()->forWorkspace($actor->workspace)->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($editing !== []) {
                $this->applyEdits($actor, $task, $editing, $auditSource);
            }

            if (isset($data['status']) && $data['status'] !== $task->status->value) {
                $this->transition($actor, $task, TaskStatus::from($data['status']), $auditSource);
            }

            return $task;
        });
    }

    /** @return list<TaskStatus> */
    public static function allowedTransitions(TaskStatus $from, bool $assignee, bool $creator, bool $manage): array
    {
        $allowed = [];

        if ($from === TaskStatus::Todo && ($assignee || $manage)) {
            $allowed[] = TaskStatus::InProgress;
        }

        if (in_array($from, [TaskStatus::Todo, TaskStatus::InProgress], true) && ($assignee || $creator || $manage)) {
            $allowed[] = TaskStatus::Done;
        }

        if (in_array($from, [TaskStatus::Todo, TaskStatus::InProgress], true) && $manage) {
            $allowed[] = TaskStatus::Cancelled;
        }

        if ($from === TaskStatus::Done && $manage) {
            $allowed[] = TaskStatus::Todo;
        }

        return $allowed;
    }

    /** @return list<TaskStatus> */
    public function transitionsFor(Actor $actor, Task $task): array
    {
        return self::allowedTransitions(
            $task->status,
            $task->assignee_id === $actor->id(),
            $task->created_by === $actor->id(),
            $this->scope->canManageTask($actor, $task),
        );
    }

    /** @throws OpsbotException */
    public function transition(Actor $actor, Task $task, TaskStatus $to, string $auditSource = Auditor::SOURCE_WEB): Task
    {
        if (! in_array($to, $this->transitionsFor($actor, $task), true)) {
            throw OpsbotException::forbidden('tasks.transition_forbidden');
        }

        $from = $task->status;
        $task->status = $to;
        $task->completed_at = $to === TaskStatus::Done ? now() : ($to === TaskStatus::Todo ? null : $task->completed_at);
        $task->cancelled_at = $to === TaskStatus::Cancelled ? now() : $task->cancelled_at;
        $task->save();

        if ($to === TaskStatus::Done || $to === TaskStatus::Cancelled) {
            $this->cancelPending($task);
        } elseif ($from === TaskStatus::Done && $to === TaskStatus::Todo) {
            $this->replan($task, true);
        }

        $this->auditor->record(
            'task.status_changed',
            $task,
            ['status' => [$from->value, $to->value]],
            actor: $actor->user,
            workspace: $actor->workspace,
            subjectLabel: $task->title,
            source: $auditSource,
        );

        return $task;
    }

    public function reassignOpenTasks(Workspace $workspace, User $from, ?User $actor): int
    {
        $tasks = Task::query()
            ->forWorkspace($workspace)
            ->where('assignee_id', $from->id)
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->get();

        $count = 0;

        foreach ($tasks as $task) {
            $department = $task->department_id !== null
                ? Department::query()->forWorkspace($workspace)->whereKey($task->department_id)->first()
                : null;

            $manager = $department !== null ? $this->routing->activeManager($workspace, $department->manager_user_id) : null;

            if ($manager === null || $manager->id === $from->id) {
                $manager = $workspace->oldestActiveOwner();
            }

            if ($manager === null || $manager->id === $from->id) {
                continue;
            }

            $task->assignee_id = $manager->id;
            $task->save();
            $this->replan($task, false);

            $this->auditor->record(
                'task.assigned',
                $task,
                ['assignee_id' => [$from->id, $manager->id]],
                ['reason' => 'offboarding'],
                actor: $actor,
                workspace: $workspace,
                subjectLabel: $task->title,
            );

            $this->notifyAssigned($task, $manager, $actor);
            $count++;
        }

        return $count;
    }

    public function replan(Task $task, bool $resetSent): void
    {
        $existing = TaskReminder::query()->forWorkspace($task->workspace_id)->where('task_id', $task->id)->get()->keyBy(fn (TaskReminder $reminder): string => $reminder->kind->value);

        if (! $task->isOpen() || $task->due_at === null || $task->assignee_id === null) {
            $this->cancelPending($task);

            return;
        }

        $workspace = Workspace::query()->findOrFail($task->workspace_id);
        $settings = $workspace->settings();

        $plan = TaskReminderPlanner::plan(
            $task->due_at,
            $workspace->zone(),
            $settings->quietStart(),
            $settings->quietEnd(),
            now(),
        );

        $planned = [];

        foreach ($plan as $item) {
            $kind = $item['kind']->value;
            $planned[] = $kind;
            $row = $existing->get($kind);

            if ($row !== null && $row->status === ReminderStatus::Sent && ! $resetSent) {
                continue;
            }

            TaskReminder::query()->updateOrCreate(
                ['task_id' => $task->id, 'kind' => $kind],
                [
                    'workspace_id' => $task->workspace_id,
                    'planned_at' => $item['planned_at'],
                    'status' => ReminderStatus::Planned,
                    'skip_reason' => null,
                    'sent_at' => null,
                    'outbox_id' => null,
                ],
            );
        }

        TaskReminder::query()
            ->forWorkspace($task->workspace_id)
            ->where('task_id', $task->id)
            ->where('status', ReminderStatus::Planned->value)
            ->whereNotIn('kind', $planned === [] ? [''] : $planned)
            ->update(['status' => ReminderStatus::Cancelled->value]);

        $task->unsetRelation('reminders');
    }

    public function notifyAssigned(Task $task, User $assignee, ?User $assignedBy): void
    {
        $workspace = Workspace::query()->findOrFail($task->workspace_id);
        $incident = $task->incident_id !== null ? Incident::query()->forWorkspace($workspace)->find($task->incident_id) : null;

        $this->notifier->notify(Topics::TASK_ASSIGNED, $assignee, $workspace->id, fn (string $locale): array => [
            'task' => [
                'id' => $task->id,
                'display_number' => DisplayNumber::task($task->number),
                'title' => $task->title,
                'due_local' => LocalTime::dateTime($task->due_at, $workspace->zone(), $locale),
                'incident_display_number' => $incident !== null ? DisplayNumber::incident($incident->number) : null,
            ],
            'assigned_by_name' => $assignedBy?->displayName(),
            'web_url' => OpsNotifier::url('/tasks?focus='.$task->id),
        ]);
    }

    private function cancelPending(Task $task): void
    {
        TaskReminder::query()
            ->forWorkspace($task->workspace_id)
            ->where('task_id', $task->id)
            ->where('status', ReminderStatus::Planned->value)
            ->update(['status' => ReminderStatus::Cancelled->value]);

        $task->unsetRelation('reminders');
    }

    /** @param  array{title?: string, description?: string|null, due_at?: CarbonImmutable|null, assignee_id?: int|null}  $data */
    private function applyEdits(Actor $actor, Task $task, array $data, string $auditSource): void
    {
        $before = ['title' => $task->title, 'description' => $task->description, 'due_at' => $task->due_at?->toIso8601String(), 'assignee_id' => $task->assignee_id];

        if (array_key_exists('title', $data)) {
            $task->title = Str::limit(trim((string) $data['title']), 160, '');
        }

        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            $task->description = $description === '' ? null : $description;
        }

        if (array_key_exists('due_at', $data)) {
            $task->due_at = $data['due_at']?->utc();
        }

        $newAssignee = null;

        if (array_key_exists('assignee_id', $data)) {
            $newAssignee = $data['assignee_id'] !== null ? $this->activeMember($actor->workspace, (int) $data['assignee_id']) : null;
            $task->assignee_id = $newAssignee?->id;
        }

        $dueChanged = $task->isDirty('due_at');
        $assigneeChanged = $task->isDirty('assignee_id');
        $task->save();

        $after = ['title' => $task->title, 'description' => $task->description, 'due_at' => $task->due_at?->toIso8601String(), 'assignee_id' => $task->assignee_id];
        $changes = Auditor::diff($before, $after);

        if ($dueChanged || $assigneeChanged) {
            $this->replan($task, $dueChanged);
        }

        if ($assigneeChanged) {
            $this->auditor->record('task.assigned', $task, ['assignee_id' => $changes['assignee_id'] ?? [null, null]], actor: $actor->user, workspace: $actor->workspace, subjectLabel: $task->title, source: $auditSource);
            unset($changes['assignee_id']);

            if ($newAssignee !== null && $newAssignee->id !== $actor->id()) {
                $this->notifyAssigned($task, $newAssignee, $actor->user);
            }
        }

        if ($changes !== []) {
            $this->auditor->record('task.updated', $task, $changes, actor: $actor->user, workspace: $actor->workspace, subjectLabel: $task->title, source: $auditSource);
        }
    }

    /** @throws OpsbotException */
    private function assertDepartment(Actor $actor, ?int $departmentId, bool $strict): void
    {
        if ($departmentId !== null && ! Department::query()->forWorkspace($actor->workspace)->whereKey($departmentId)->exists()) {
            throw new OpsbotException('departments.invalid');
        }

        if ($strict && ! $actor->can('tasks.view_all') && $departmentId !== null && ! $actor->inDepartment($departmentId)) {
            throw OpsbotException::forbidden('tasks.department_scope');
        }
    }

    /** @throws OpsbotException */
    private function activeMember(Workspace $workspace, int $userId): User
    {
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->first();

        if ($membership === null || $membership->status !== MemberStatus::Active) {
            throw new OpsbotException('tasks.assignee_invalid');
        }

        return User::query()->findOrFail($userId);
    }
}
