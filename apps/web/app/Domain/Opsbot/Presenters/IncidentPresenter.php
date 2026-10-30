<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Presenters;

use App\Domain\Opsbot\Support\DisplayNumber;
use App\Domain\Opsbot\Support\LocalTime;
use App\Models\Incident;
use App\Models\IncidentEvent;
use Carbon\CarbonInterface;

final class IncidentPresenter
{
    /** @return array<int|string, mixed> */
    public static function relations(): array
    {
        return ['department', 'reporter', 'assignee'];
    }

    /** @return array<string, mixed> */
    public static function present(Incident $incident, ?CarbonInterface $now = null): array
    {
        return [
            'id' => $incident->id,
            'number' => $incident->number,
            'display_number' => DisplayNumber::incident($incident->number),
            'category' => $incident->category->value,
            'severity' => $incident->severity->value,
            'status' => $incident->status->value,
            'title' => $incident->title,
            'description' => $incident->description,
            'client_ref' => $incident->client_ref,
            'department' => Refs::department($incident->department),
            'reporter' => Refs::user($incident->reporter),
            'assignee' => Refs::user($incident->assignee),
            'source' => $incident->source->value,
            'ai_query_id' => $incident->ai_query_id,
            'tasks_count' => (int) ($incident->tasks_count ?? $incident->tasks()->count()),
            'age_hours' => LocalTime::ageHours($incident->created_at, $now),
            'created_at' => $incident->created_at->toIso8601String(),
            'updated_at' => $incident->updated_at->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'closed_at' => $incident->closed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function event(IncidentEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'actor' => Refs::user($event->actor),
            'from' => $event->from_value,
            'to' => $event->to_value,
            'body' => $event->body,
            'created_at' => $event->created_at->toIso8601String(),
        ];
    }
}
