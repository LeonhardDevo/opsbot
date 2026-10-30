<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum Currency: string
{
    use EnumValues;

    case USD = 'USD';
    case EUR = 'EUR';
    case RUB = 'RUB';

    public static function labelPrefix(): string
    {
        return 'billing.currency';
    }
}
