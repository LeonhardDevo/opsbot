<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum DocumentSource: string
{
    use EnumValues;

    case Upload = 'upload';
    case StarterPack = 'starter_pack';
    case Faq = 'faq';

    public static function labelPrefix(): string
    {
        return 'documents.source';
    }
}
