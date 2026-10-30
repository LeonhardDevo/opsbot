<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum TaskSource: string
{
    use EnumValues;

    case Web = 'web';
    case Bot = 'bot';
    case Incident = 'incident';
    case AiProposal = 'ai_proposal';

    public static function labelPrefix(): string
    {
        return 'tasks.source';
    }
}
