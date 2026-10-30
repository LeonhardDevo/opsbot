<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum AnswerKind: string
{
    use EnumValues;

    case Document = 'document';
    case ToolData = 'tool_data';

    public static function labelPrefix(): string
    {
        return 'opsbot.answer_kind';
    }
}
