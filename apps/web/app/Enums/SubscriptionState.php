<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum SubscriptionState: string
{
    use EnumValues;

    case Ok = 'ok';
    case Grace = 'grace';
    case Paused = 'paused';

    public static function labelPrefix(): string
    {
        return 'billing.subscription_state';
    }
}
