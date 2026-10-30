<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum DocumentStatus: string
{
    use EnumValues;

    case Queued = 'queued';
    case Processing = 'processing';
    case Indexed = 'indexed';
    case Failed = 'failed';

    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

    public static function labelPrefix(): string
    {
        return 'documents.status';
    }
}
