<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum ActionType: string
{
    use EnumValues;

    case CreateIncident = 'create_incident';
    case CreateTask = 'create_task';
    case Escalate = 'escalate';
    case OpenSource = 'open_source';
    case Ask = 'ask';

    public static function labelPrefix(): string
    {
        return 'opsbot.action_type';
    }
}
