<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum IncidentEventType: string
{
    use EnumValues;

    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case SeverityChanged = 'severity_changed';
    case CategoryChanged = 'category_changed';
    case Comment = 'comment';
    case TaskLinked = 'task_linked';

    public static function labelPrefix(): string
    {
        return 'incidents.event';
    }
}
