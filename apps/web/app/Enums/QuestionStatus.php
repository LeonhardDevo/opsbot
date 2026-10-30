<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum QuestionStatus: string
{
    use EnumValues;

    case Open = 'open';
    case Answered = 'answered';
    case ConvertedToDoc = 'converted_to_doc';
    case Dismissed = 'dismissed';

    public static function labelPrefix(): string
    {
        return 'questions.status';
    }
}
