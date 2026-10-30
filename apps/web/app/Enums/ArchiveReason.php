<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum ArchiveReason: string
{
    use EnumValues;

    case Manual = 'manual';
    case Superseded = 'superseded';

    public static function labelPrefix(): string
    {
        return 'documents.archive_reason';
    }
}
