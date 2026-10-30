<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum IncidentStatus: string
{
    use EnumValues;

    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public static function labelPrefix(): string
    {
        return 'incidents.status';
    }
}
