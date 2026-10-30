<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum DismissReason: string
{
    use EnumValues;

    case Duplicate = 'duplicate';
    case OutOfScope = 'out_of_scope';
    case Spam = 'spam';
    case Other = 'other';

    public static function labelPrefix(): string
    {
        return 'questions.dismiss_reason';
    }
}
