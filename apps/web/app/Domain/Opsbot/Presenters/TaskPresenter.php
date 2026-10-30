<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Presenters;

use App\Domain\Opsbot\Support\DisplayNumber;
use App\Models\Task;
use App\Models\TaskReminder;
use Carbon\CarbonInterface;

final class TaskPresenter
{
    /** @return list<string> */
    public static function relations(): array
    {
        return ['assignee', 'creator', 'department', 'incident', 'reminders'];
    }

    /** @return array<string, mixed> */
    public static function present(Task $task, ?CarbonInterface $now = null): array
    {
        $incident = $task->incident;

        return [
            'id' => $task->id,
            'number' => $task->number,
            'display_number' => DisplayNumber::task($task->number),
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'due_at' => $task->due_at?->toIso8601String(),
            'overdue' => $task->isOverdue($now),
            'assignee' => Refs::user($task->assignee),
            'created_by' => Refs::user($task->creator),
            'department' => Refs::department($task->department),
            'incident' => $incident !== null
                ? ['id' => $incident->id, 'display_number' => DisplayNumber::incident($incident->number)]
                : null,
            'source' => $task->source->value,
            'reminders' => array_values($task->reminders
                ->sortBy(fn (TaskReminder $reminder): int => $reminder->planned_at->getTimestamp())
                ->map(fn (TaskReminder $reminder): array => [
                    'kind' => $reminder->kind->value,
                    'planned_at' => $reminder->planned_at->toIso8601String(),
                    'status' => $reminder->status->value,
                ])
                ->values()
                ->all()),
            'created_at' => $task->created_at->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
        ];
    }
}
