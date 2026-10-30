<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum MemberStatus: string
{
    use EnumValues;

    case Invited = 'invited';
    case Active = 'active';
    case Offboarded = 'offboarded';

    public static function labelPrefix(): string
    {
        return 'employees.status';
    }
}
