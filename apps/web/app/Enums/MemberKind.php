<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum MemberKind: string
{
    use EnumValues;

    case Web = 'web';
    case TelegramOnly = 'telegram_only';

    public static function labelPrefix(): string
    {
        return 'employees.kind';
    }
}
