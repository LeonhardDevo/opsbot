<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Support;

use Illuminate\Support\Facades\URL;

final class SignedLinks
{
    public static function document(int $documentId, int $userId, ?int $chunkId = null, ?int $page = null): string
    {
        return URL::temporarySignedRoute(
            'share.document',
            now()->addMinutes((int) config('opsbot.share_links.ttl_minutes', 30)),
            array_filter([
                'document' => $documentId,
                'u' => $userId,
                'c' => $chunkId,
                'p' => $page,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    public static function file(int $documentId, int $userId): string
    {
        return URL::temporarySignedRoute(
            'share.document.file',
            now()->addMinutes((int) config('opsbot.share_links.file_ttl_minutes', 5)),
            ['document' => $documentId, 'u' => $userId],
        );
    }
}
