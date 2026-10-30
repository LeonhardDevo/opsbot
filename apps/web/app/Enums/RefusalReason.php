<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum RefusalReason: string
{
    use EnumValues;

    case LowConfidence = 'low_confidence';
    case NotFound = 'not_found';
    case ModelRefusal = 'model_refusal';
    case Unavailable = 'unavailable';
    case LimitReached = 'limit_reached';
    case Paused = 'paused';

    public static function labelPrefix(): string
    {
        return 'opsbot.refusal_reason';
    }
}
