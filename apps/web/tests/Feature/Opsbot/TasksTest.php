<?php

declare(strict_types=1);

use App\Domain\Opsbot\TaskService;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\Task;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('encodes the task state machine', function (TaskStatus $from, bool $assignee, bool $creator, bool $manage, array $expected): void {
    expect(array_map(fn (TaskStatus $s): string => $s->value, TaskService::allowedTransitions($from, $assignee, $creator, $manage)))->toBe($expected);
})->with([
    'assignee from todo' => [TaskStatus::Todo, true, false, false, ['in_progress', 'done']],
    'creator from todo' => [TaskStatus::Todo, false, true, false, ['done']],
    'manager from todo' => [TaskStatus::Todo, false, false, true, ['in_progress', 'done', 'cancelled']],
    'assignee from in progress' => [TaskStatus::InProgress, true, false, false, ['done']],
    'manager reopens done' => [TaskStatus::Done, false, false, true, ['todo']],
    'assignee cannot reopen' => [TaskStatus::Done, true, false, false, []],
    'cancelled is terminal' => [TaskStatus::Cancelled, true, true, true, []],
    'stranger' => [TaskStatus::Todo, false, false, false, []],
]);

it('creates tasks on the web for managers with due dates in the workspace timezone', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $workspace->forceFill(['timezone' => 'Europe/Moscow'])->save();
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace, ['locale' => 'ru']);
    $department = $this->department($workspace, 'mine');
    $this->inDepartment($manager, $department);

    $this->post(route('tasks.store'), [
        'title' => 'Return the keys',
        'description' => 'Box at reception',
        'due_at' => '2026-12-01T18:00',
        'assignee_id' => $employee->id,
    ])->assertSessionHas('success');

    $task = Task::query()->sole();
    expect($task->due_at?->toIso8601String())->toBe('2026-12-01T15:00:00+00:00')
        ->and($task->department_id)->toBe($department->id)
        ->and($task->created_by)->toBe($manager->id)
        ->and($task->reminders()->count())->toBe(6)
        ->and(AuditLog::query()->where('action', 'task.created')->exists())->toBeTrue();

    $message = OutboxMessage::query()->topic('opsbot.task.assigned')->sole();
    expect($message->payload['task'])->toMatchArray(['display_number' => 'T-1', 'title' => 'Return the keys', 'due_local' => '1 дек., 18:00'])
        ->and($message->payload['assigned_by_name'])->toBe($manager->name);
});

it('does not notify self-assigned creators and blocks employees from creating on the web', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);

    $this->post(route('tasks.store'), ['title' => 'My own task', 'assignee_id' => $manager->id])->assertSessionHas('success');
    expect(OutboxMessage::query()->topic('opsbot.task.assigned')->count())->toBe(0);

    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $this->actingAs($employee)->post(route('tasks.store'), ['title' => 'Sneaky task'])->assertSessionHas('error', __('errors.tasks.create_forbidden'));

    expect(Task::query()->count())->toBe(1);
});

it('moves tasks through statuses by role and keeps cancelled terminal', function (): void {
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $department = $this->department($workspace, 'mine');
    $this->inDepartment($manager, $department);
    $task = $this->task($workspace, ['assignee_id' => $employee->id, 'created_by' => $manager->id, 'department_id' => $department->id]);

    $this->actingAs($employee)->patch(route('tasks.update', $task), ['status' => 'cancelled'])->assertSessionHas('error');
    $this->actingAs($employee)->patch(route('tasks.update', $task), ['title' => 'Renamed'])->assertSessionHas('error');
    $this->actingAs($employee)->patch(route('tasks.update', $task), ['status' => 'in_progress'])->assertSessionHas('success');
    $this->actingAs($employee)->patch(route('tasks.update', $task), ['status' => 'done'])->assertSessionHas('success');

    expect($task->refresh()->status)->toBe(TaskStatus::Done)
        ->and($task->completed_at)->not->toBeNull();

    $this->actingAs($manager)->patch(route('tasks.update', $task), ['status' => 'todo'])->assertSessionHas('success');
    $this->actingAs($manager)->patch(route('tasks.update', $task), ['status' => 'cancelled'])->assertSessionHas('success');
    $this->actingAs($manager)->patch(route('tasks.update', $task), ['status' => 'todo'])->assertSessionHas('error');

    expect($task->refresh()->status)->toBe(TaskStatus::Cancelled)
        ->and(AuditLog::query()->where('action', 'task.status_changed')->count())->toBe(4);
});

it('reassigns with an audit entry and a notification to the new assignee', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    [$first] = $this->member(WorkspaceRole::Employee, $workspace);
    [$second] = $this->member(WorkspaceRole::Employee, $workspace);
    $task = $this->task($workspace, ['assignee_id' => $first->id, 'created_by' => $manager->id]);

    $this->patch(route('tasks.update', $task), ['assignee_id' => $second->id, 'title' => 'Updated title'])->assertSessionHas('success');

    expect($task->refresh()->assignee_id)->toBe($second->id)
        ->and(AuditLog::query()->where('action', 'task.assigned')->sole()->changes)->toEqual(['assignee_id' => [$first->id, $second->id]])
        ->and(AuditLog::query()->where('action', 'task.updated')->sole()->changes)->toHaveKey('title')
        ->and(OutboxMessage::query()->topic('opsbot.task.assigned')->sole()->payload['recipient']['user_id'])->toBe($second->id);
});

it('lists tasks with board, overdue counts and filters', function (): void {
    CarbonImmutable::setTestNow('2026-10-22 12:00:00');
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $this->task($workspace, ['assignee_id' => $owner->id, 'due_at' => now()->subDay()]);
    $this->task($workspace, ['status' => TaskStatus::InProgress, 'due_at' => now()->addHours(2)]);
    $this->task($workspace, ['status' => TaskStatus::Done, 'due_at' => now()->subDays(3)]);

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tasks/Index', false)
            ->where('counts', ['todo' => 1, 'in_progress' => 1, 'done' => 1, 'cancelled' => 0, 'overdue' => 1])
            ->where('board.todo.0.overdue', true)
            ->where('board.done.0.overdue', false)
            ->where('can', ['create' => true, 'manage' => true]));

    $this->get(route('tasks.index', ['due' => 'overdue']))->assertInertia(fn ($page) => $page->has('tasks.data', 1));
    $this->get(route('tasks.index', ['due' => 'today']))->assertInertia(fn ($page) => $page->has('tasks.data', 1));
    $this->get(route('tasks.index', ['assignee' => 'me']))->assertInertia(fn ($page) => $page->has('tasks.data', 1));
});

it('shows employees only their own tasks', function (): void {
    [$employee, $workspace] = $this->signInMember(WorkspaceRole::Employee);
    $this->task($workspace, ['assignee_id' => $employee->id]);
    $this->task($workspace);

    $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->has('tasks.data', 1)->where('can', ['create' => false, 'manage' => false]));
});

it('serves own open and overdue tasks to the ai service and the bot', function (): void {
    CarbonImmutable::setTestNow('2026-10-22 12:00:00');
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee, null, ['locale' => 'en']);
    $workspace->forceFill(['timezone' => 'Europe/Moscow'])->save();
    $overdue = $this->task($workspace, ['assignee_id' => $employee->id, 'due_at' => CarbonImmutable::parse('2026-10-21 11:30', 'UTC')]);
    $this->task($workspace, ['assignee_id' => $employee->id, 'due_at' => CarbonImmutable::parse('2026-10-25 09:00', 'UTC')]);
    $this->task($workspace, ['assignee_id' => $employee->id, 'status' => TaskStatus::Done]);
    $this->linkTelegram($employee, $workspace, 'platform', 321);

    $this->internal('GET', '/internal/v1/opsbot/tasks?workspace_id='.$workspace->id.'&user_id='.$employee->id.'&status=overdue', service: 'ai')
        ->assertOk()
        ->assertExactJson(['tasks' => [[
            'id' => $overdue->id,
            'display_number' => 'T-1',
            'title' => 'Return keys',
            'status' => 'todo',
            'due_at' => '2026-10-21T11:30:00+00:00',
            'due_local' => 'Oct 21, 2:30 PM',
            'overdue' => true,
        ]]]);

    $this->internal('GET', '/internal/v1/opsbot/tasks?bot_key=platform&telegram_user_id=321')->assertOk()->assertJsonCount(2, 'tasks');
});

it('starts and completes tasks from telegram callbacks', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee, null, ['locale' => 'ru']);
    $this->linkTelegram($employee, $workspace, 'platform', 77);
    $task = $this->task($workspace, ['assignee_id' => $employee->id]);
    $foreign = $this->task($workspace);

    $this->internal('POST', '/internal/v1/telegram/actions', ['bot_key' => 'platform', 'type' => 'task_start', 'payload' => ['arg' => (string) $task->id], 'telegram_user_id' => 77, 'chat_id' => 77])
        ->assertOk()->assertJson(['ok' => true, 'message' => 'Задача T-1 взята в работу.']);
    $this->internal('POST', '/internal/v1/telegram/actions', ['bot_key' => 'platform', 'type' => 'task_done', 'payload' => ['arg' => (string) $task->id], 'telegram_user_id' => 77, 'chat_id' => 77])
        ->assertOk()->assertJson(['ok' => true, 'message' => 'Задача T-1 выполнена.']);
    $this->internal('POST', '/internal/v1/telegram/actions', ['bot_key' => 'platform', 'type' => 'task_done', 'payload' => ['arg' => (string) $task->id], 'telegram_user_id' => 77, 'chat_id' => 77])
        ->assertOk()->assertJson(['ok' => true, 'message' => 'Задача T-1 уже в этом статусе.']);
    $this->internal('POST', '/internal/v1/telegram/actions', ['bot_key' => 'platform', 'type' => 'task_done', 'payload' => ['arg' => (string) $foreign->id], 'telegram_user_id' => 77, 'chat_id' => 77])
        ->assertOk()->assertJson(['ok' => false, 'alert' => true]);

    expect($task->refresh()->status)->toBe(TaskStatus::Done)
        ->and($foreign->refresh()->status)->toBe(TaskStatus::Todo);
});
