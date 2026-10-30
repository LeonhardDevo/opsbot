<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum IncidentCategory: string
{
    use EnumValues;

    case Damage = 'damage';
    case Complaint = 'complaint';
    case NoAccess = 'no_access';
    case Injury = 'injury';
    case LostItem = 'lost_item';
    case Other = 'other';

    public static function labelPrefix(): string
    {
        return 'incidents.category';
    }
}
