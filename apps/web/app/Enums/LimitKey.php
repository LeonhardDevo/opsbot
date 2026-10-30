<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum LimitKey: string
{
    use EnumValues;

    case Seats = 'seats';
    case Documents = 'documents';
    case StorageMb = 'storage_mb';
    case AiQueriesMonthly = 'ai_queries_monthly';
    case MaxPagesPerDocument = 'max_pages_per_document';
    case MaxFileMb = 'max_file_mb';
    case Bots = 'bots';

    public static function labelPrefix(): string
    {
        return 'billing.limits';
    }
}
