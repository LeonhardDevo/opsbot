<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum QuestionReason: string
{
    use EnumValues;

    case LowConfidence = 'low_confidence';
    case NotFound = 'not_found';
    case Conflict = 'conflict';
    case UserRequest = 'user_request';
    case LimitReached = 'limit_reached';

    public static function labelPrefix(): string
    {
        return 'questions.reason';
    }
}
