<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum ReminderKind: string
{
    use EnumValues;

    case PreDue = 'pre_due';
    case Due = 'due';
    case Overdue1 = 'overdue_1';
    case Overdue2 = 'overdue_2';
    case Overdue3 = 'overdue_3';
    case Escalation = 'escalation';

    public static function labelPrefix(): string
    {
        return 'tasks.reminder';
    }
}
