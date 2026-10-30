<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Notifications;

use App\Locale\WithLocale;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Outbox\Outbox;
use Closure;

final readonly class OpsNotifier
{
    public function __construct(private Outbox $outbox) {}

    /** @param  Closure(string): array<string, mixed>  $build */
    public function notify(string $topic, User $recipient, int $workspaceId, Closure $build): OutboxMessage
    {
        $locale = $recipient->preferredLocale();
        $data = WithLocale::run($locale, fn (): array => $build($locale));

        return $this->outbox->publish($topic, [
            'workspace_id' => $workspaceId,
            'recipient' => ['user_id' => $recipient->id, 'locale' => $locale],
            ...$data,
        ], $workspaceId);
    }

    public static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
