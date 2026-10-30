<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum IncidentSource: string
{
    use EnumValues;

    case Bot = 'bot';
    case Web = 'web';
    case AiProposal = 'ai_proposal';

    public static function labelPrefix(): string
    {
        return 'incidents.source';
    }
}
