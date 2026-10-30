<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum TaskStatus: string
{
    use EnumValues;

    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public static function labelPrefix(): string
    {
        return 'tasks.status';
    }
}
