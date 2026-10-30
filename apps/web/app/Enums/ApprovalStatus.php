<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum ApprovalStatus: string
{
    use EnumValues;

    case Draft = 'draft';
    case Approved = 'approved';
    case Archived = 'archived';

    public static function labelPrefix(): string
    {
        return 'documents.approval';
    }
}
