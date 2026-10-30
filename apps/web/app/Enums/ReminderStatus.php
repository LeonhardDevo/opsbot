<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum ReminderStatus: string
{
    use EnumValues;

    case Planned = 'planned';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public static function labelPrefix(): string
    {
        return 'tasks.reminder_status';
    }
}
