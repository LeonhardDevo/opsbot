<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum Severity: string
{
    use EnumValues;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function labelPrefix(): string
    {
        return 'incidents.severity';
    }
}
