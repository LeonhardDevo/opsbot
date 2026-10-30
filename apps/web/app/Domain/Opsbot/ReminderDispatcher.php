<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Domain\Opsbot\Notifications\OpsNotifier;
use App\Domain\Opsbot\Support\DisplayNumber;
use App\Domain\Opsbot\Support\LocalTime;
use App\Enums\ReminderKind;
use App\Enums\ReminderStatus;
use App\Enums\RoutingCategory;
use App\Enums\SubscriptionState as State;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\Workspace;
use App\Outbox\Topics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ReminderDispatcher
{
    public function __construct(
        private OpsNotifier $notifier,
        private RoutingResolver $routing,
        private SubscriptionState $subscriptions,
    ) {}

    /** @return array{sent: int, skipped: int} */
    public function dispatch(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $limit = (int) config('opsbot.reminders.dispatch_batch', 200);

        return DB::transaction(function () use ($now, $limit): array {
            $ids = DB::select(
                'SELECT id FROM task_reminders WHERE status = ? AND planned_at <= ? ORDER BY planned_at, id LIMIT '.$limit.' FOR UPDATE SKIP LOCKED',
                [ReminderStatus::Planned->value, $now->utc()],
            );

            $sent = 0;
            $skipped = 0;
            $workspaces = [];

            foreach ($ids as $row) {
                $reminder = TaskReminder::query()->withoutGlobalScopes()->with('task')->find((int) $row->id);

                if ($reminder === null) {
                    continue;
                }

                $workspace = $workspaces[$reminder->workspace_id] ??= Workspace::query()->with(['subscription', 'opsSettings'])->findOrFail($reminder->workspace_id);
                $reason = $this->skipReason($reminder->task, $workspace, $now);

                if ($reason !== null) {
                    $reminder->forceFill(['status' => ReminderStatus::Skipped, 'skip_reason' => $reason])->save();
                    $skipped++;

                    continue;
                }

                $outboxId = $this->send($reminder, $reminder->task, $workspace, $now);

                if ($outboxId === null) {
                    $reminder->forceFill(['status' => ReminderStatus::Skipped, 'skip_reason' => 'no_recipient'])->save();
                    $skipped++;

                    continue;
                }

                $reminder->forceFill([
                    'status' => ReminderStatus::Sent,
                    'sent_at' => $now,
                    'outbox_id' => $outboxId,
                ])->save();
                $sent++;
            }

            return ['sent' => $sent, 'skipped' => $skipped];
        });
    }

    private function skipReason(Task $task, Workspace $workspace, CarbonImmutable $now): ?string
    {
        return match (true) {
            ! $task->isOpen() => 'task_closed',
            $task->assignee_id === null => 'no_assignee',
            ! $workspace->settings()->reminders_enabled => 'disabled',
            $this->subscriptions->for($workspace, $now) === State::Paused => 'paused',
            default => null,
        };
    }

    private function send(TaskReminder $reminder, Task $task, Workspace $workspace, CarbonImmutable $now): ?string
    {
        $overdueHours = $task->due_at !== null && $task->due_at->lt($now) ? LocalTime::ageHours($task->due_at, $now) : 0;

        $taskPayload = fn (string $locale): array => [
            'id' => $task->id,
            'display_number' => DisplayNumber::task($task->number),
            'title' => $task->title,
            'due_local' => LocalTime::dateTime($task->due_at, $workspace->zone(), $locale),
            'overdue_hours' => $overdueHours,
        ];

        if ($reminder->kind === ReminderKind::Escalation) {
            $recipient = $this->routing->resolve($workspace, RoutingCategory::Task, $task->department_id)['user'];

            if ($recipient === null || $recipient->id === $task->assignee_id) {
                $recipient = $workspace->oldestActiveOwner();
            }

            if ($recipient === null) {
                return null;
            }

            $assignee = $task->assignee;

            return $this->notifier->notify(Topics::TASK_ESCALATED, $recipient, $workspace->id, fn (string $locale): array => [
                'task' => $taskPayload($locale),
                'assignee_name' => $assignee?->displayName(),
                'web_url' => OpsNotifier::url('/tasks?focus='.$task->id),
            ])->id;
        }

        $assignee = $task->assignee;

        if ($assignee === null) {
            return null;
        }

        return $this->notifier->notify(Topics::TASK_REMINDER_DUE, $assignee, $workspace->id, fn (string $locale): array => [
            'reminder_id' => $reminder->id,
            'kind' => $reminder->kind->value,
            'task' => $taskPayload($locale),
        ])->id;
    }
}
