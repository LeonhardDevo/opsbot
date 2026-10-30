<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum ActionStatus: string
{
    use EnumValues;

    case Proposed = 'proposed';
    case Executed = 'executed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Failed = 'failed';

    public static function labelPrefix(): string
    {
        return 'opsbot.action_status';
    }
}
