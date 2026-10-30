<?php

declare(strict_types=1);

use App\Domain\Opsbot\ReminderDispatcher;
use App\Domain\Opsbot\TaskReminderPlanner;
use App\Domain\Opsbot\TaskService;
use App\Enums\ReminderKind;
use App\Enums\ReminderStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\OutboxMessage;
use App\Models\Subscription;
use App\Models\TaskReminder;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array<string, string> */
function planned(CarbonImmutable $due, string $timezone, CarbonImmutable $now, string $start = '21:00', string $end = '08:00'): array
{
    $plan = [];

    foreach (TaskReminderPlanner::plan($due, $timezone, $start, $end, $now) as $item) {
        $plan[$item['kind']->value] = $item['planned_at']->format('Y-m-d H:i');
    }

    return $plan;
}

it('plans all reminders for Moscow in UTC', function (): void {
    $due = CarbonImmutable::parse('2026-10-23 15:00', 'Europe/Moscow');

    expect(planned($due, 'Europe/Moscow', CarbonImmutable::parse('2026-10-22 10:00', 'UTC')))->toBe([
        'pre_due' => '2026-10-23 10:00',
        'due' => '2026-10-23 12:00',
        'overdue_1' => '2026-10-24 12:00',
        'overdue_2' => '2026-10-25 12:00',
        'overdue_3' => '2026-10-26 12:00',
        'escalation' => '2026-10-26 12:01',
    ]);
});

it('skips reminders whose time has already passed', function (): void {
    $due = CarbonImmutable::parse('2026-10-23 15:00', 'Europe/Moscow');

    expect(array_keys(planned($due, 'Europe/Moscow', CarbonImmutable::parse('2026-10-23 11:00', 'UTC'))))
        ->toBe(['due', 'overdue_1', 'overdue_2', 'overdue_3', 'escalation']);
});

it('shifts reminders out of quiet hours to the next morning', function (): void {
    $due = CarbonImmutable::parse('2026-10-23 22:30', 'Europe/Moscow');

    expect(planned($due, 'Europe/Moscow', CarbonImmutable::parse('2026-10-22 10:00', 'UTC')))->toMatchArray([
        'pre_due' => '2026-10-23 17:30',
        'due' => '2026-10-24 05:00',
        'overdue_1' => '2026-10-25 05:00',
        'overdue_3' => '2026-10-27 05:00',
        'escalation' => '2026-10-27 05:01',
    ]);
});

it('respects quiet hour boundaries exactly', function (string $local, string $expected): void {
    $shifted = TaskReminderPlanner::shiftOutOfQuietHours(CarbonImmutable::parse('2026-10-23 '.$local, 'Europe/Moscow'), '21:00', '08:00');

    expect($shifted->format('Y-m-d H:i'))->toBe($expected);
})->with([
    ['20:59', '2026-10-23 20:59'],
    ['21:00', '2026-10-24 08:00'],
    ['23:45', '2026-10-24 08:00'],
    ['00:10', '2026-10-23 08:00'],
    ['07:59', '2026-10-23 08:00'],
    ['08:00', '2026-10-23 08:00'],
]);

it('supports daytime quiet windows and disabled quiet hours', function (): void {
    expect(TaskReminderPlanner::shiftOutOfQuietHours(CarbonImmutable::parse('2026-10-23 13:30'), '13:00', '14:00')->format('H:i'))->toBe('14:00')
        ->and(TaskReminderPlanner::inQuietHours(CarbonImmutable::parse('2026-10-23 03:00'), '00:00', '00:00'))->toBeFalse();
});

it('keeps wall-clock times across the New York DST changes', function (): void {
    $spring = planned(CarbonImmutable::parse('2026-03-07 10:00', 'America/New_York'), 'America/New_York', CarbonImmutable::parse('2026-03-01 00:00', 'UTC'));
    $autumn = planned(CarbonImmutable::parse('2026-10-31 10:00', 'America/New_York'), 'America/New_York', CarbonImmutable::parse('2026-10-25 00:00', 'UTC'));

    expect($spring)->toMatchArray([
        'due' => '2026-03-07 15:00',
        'overdue_1' => '2026-03-08 14:00',
        'overdue_2' => '2026-03-09 14:00',
    ])->and($autumn)->toMatchArray([
        'due' => '2026-10-31 14:00',
        'overdue_1' => '2026-11-01 15:00',
        'overdue_2' => '2026-11-02 15:00',
    ]);
});

it('shifts a night deadline on the DST night to the local morning', function (): void {
    $plan = planned(CarbonImmutable::parse('2026-03-08 03:30', 'America/New_York'), 'America/New_York', CarbonImmutable::parse('2026-03-01 00:00', 'UTC'));

    expect($plan['pre_due'])->toBe('2026-03-08 12:00')
        ->and($plan['due'])->toBe('2026-03-08 12:00');
});

it('plans, re-plans and cancels reminder rows with the task lifecycle', function (): void {
    CarbonImmutable::setTestNow('2026-10-22 06:00:00');
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $workspace->forceFill(['timezone' => 'Europe/Moscow'])->save();
    $actor = $this->actorFor($manager, $workspace);
    $service = app(TaskService::class);

    $task = $service->create($actor, [
        'title' => 'Return keys',
        'due_at' => CarbonImmutable::parse('2026-10-23 15:00', 'Europe/Moscow'),
        'assignee_id' => $employee->id,
    ], TaskSource::Web);

    expect(TaskReminder::query()->where('task_id', $task->id)->where('status', 'planned')->count())->toBe(6);

    $service->update($actor, $task, ['due_at' => CarbonImmutable::parse('2026-10-24 12:00', 'Europe/Moscow')]);
    expect(TaskReminder::query()->where('task_id', $task->id)->where('kind', 'due')->firstOrFail()->planned_at->format('Y-m-d H:i'))->toBe('2026-10-24 09:00');

    $service->update($actor, $task->refresh(), ['status' => 'done']);
    expect(TaskReminder::query()->where('task_id', $task->id)->where('status', 'planned')->count())->toBe(0)
        ->and(TaskReminder::query()->where('task_id', $task->id)->where('status', 'cancelled')->count())->toBe(6);

    $service->update($actor, $task->refresh(), ['status' => 'todo']);
    expect(TaskReminder::query()->where('task_id', $task->id)->where('status', 'planned')->count())->toBe(6);
});

it('dispatches due reminders once and records the outbox id', function (): void {
    CarbonImmutable::setTestNow('2026-10-23 12:00:30');
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace, ['locale' => 'ru']);
    $workspace->forceFill(['timezone' => 'Europe/Moscow'])->save();

    $task = $this->task($workspace, ['assignee_id' => $employee->id, 'created_by' => $manager->id, 'due_at' => CarbonImmutable::parse('2026-10-23 12:00', 'UTC')]);
    $due = TaskReminder::query()->create(['workspace_id' => $workspace->id, 'task_id' => $task->id, 'kind' => ReminderKind::Due, 'planned_at' => now()->subSeconds(30), 'status' => ReminderStatus::Planned]);
    TaskReminder::query()->create(['workspace_id' => $workspace->id, 'task_id' => $task->id, 'kind' => ReminderKind::Overdue1, 'planned_at' => now()->addDay(), 'status' => ReminderStatus::Planned]);

    $this->internal('POST', '/internal/v1/opsbot/reminders/dispatch', [])->assertOk()->assertExactJson(['sent' => 1, 'skipped' => 0]);
    $this->internal('POST', '/internal/v1/opsbot/reminders/dispatch', [])->assertOk()->assertExactJson(['sent' => 0, 'skipped' => 0]);

    $message = OutboxMessage::query()->topic('opsbot.task.reminder_due')->sole();
    expect($due->refresh()->status)->toBe(ReminderStatus::Sent)
        ->and($due->outbox_id)->toBe($message->id)
        ->and($message->payload)->toMatchArray([
            'workspace_id' => $workspace->id,
            'recipient' => ['user_id' => $employee->id, 'locale' => 'ru'],
            'reminder_id' => $due->id,
            'kind' => 'due',
        ])
        ->and($message->payload['task'])->toMatchArray(['id' => $task->id, 'display_number' => 'T-1', 'due_local' => '23 окт., 15:00', 'overdue_hours' => 0]);
});

it('skips reminders of closed tasks, unassigned tasks, paused workspaces and disabled reminders', function (): void {
    CarbonImmutable::setTestNow('2026-10-23 12:00:00');
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager);

    $done = $this->task($workspace, ['assignee_id' => $manager->id, 'status' => TaskStatus::Done]);
    $unassigned = $this->task($workspace);

    foreach ([$done, $unassigned] as $task) {
        TaskReminder::query()->create(['workspace_id' => $workspace->id, 'task_id' => $task->id, 'kind' => ReminderKind::Due, 'planned_at' => now()->subMinute(), 'status' => ReminderStatus::Planned]);
    }

    [$other, $paused] = $this->member(WorkspaceRole::Owner);
    Subscription::query()->create(['workspace_id' => $paused->id, 'plan' => 'pro', 'status' => SubscriptionStatus::PastDue, 'past_due_since' => now()->subDays(8)]);
    $pausedTask = $this->task($paused, ['assignee_id' => $other->id]);
    TaskReminder::query()->create(['workspace_id' => $paused->id, 'task_id' => $pausedTask->id, 'kind' => ReminderKind::Due, 'planned_at' => now()->subMinute(), 'status' => ReminderStatus::Planned]);

    [$third, $quiet] = $this->member(WorkspaceRole::Owner);
    $quiet->settings()->forceFill(['reminders_enabled' => false])->save();
    $quietTask = $this->task($quiet, ['assignee_id' => $third->id]);
    TaskReminder::query()->create(['workspace_id' => $quiet->id, 'task_id' => $quietTask->id, 'kind' => ReminderKind::Due, 'planned_at' => now()->subMinute(), 'status' => ReminderStatus::Planned]);

    expect(app(ReminderDispatcher::class)->dispatch())->toBe(['sent' => 0, 'skipped' => 4])
        ->and(TaskReminder::query()->orderBy('id')->pluck('skip_reason')->all())->toBe(['task_closed', 'no_assignee', 'paused', 'disabled'])
        ->and(OutboxMessage::query()->count())->toBe(0);
});

it('escalates an overdue task to the department manager', function (): void {
    CarbonImmutable::setTestNow('2026-10-26 12:02:00');
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $department = $this->department($workspace, 'residential', $manager);

    $task = $this->task($workspace, ['assignee_id' => $employee->id, 'department_id' => $department->id, 'due_at' => CarbonImmutable::parse('2026-10-23 12:00', 'UTC')]);
    TaskReminder::query()->create(['workspace_id' => $workspace->id, 'task_id' => $task->id, 'kind' => ReminderKind::Escalation, 'planned_at' => now()->subMinute(), 'status' => ReminderStatus::Planned]);

    expect(app(ReminderDispatcher::class)->dispatch())->toBe(['sent' => 1, 'skipped' => 0]);

    $message = OutboxMessage::query()->topic('opsbot.task.escalated')->sole();
    expect($message->payload['recipient']['user_id'])->toBe($manager->id)
        ->and($message->payload['assignee_name'])->toBe($employee->name)
        ->and($message->payload['task']['overdue_hours'])->toBe(72)
        ->and($message->payload['web_url'])->toEndWith('/tasks?focus='.$task->id);
});
