<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Telegram;

use App\Models\OutboxMessage;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Outbox\Outbox;
use App\Outbox\Topics;

final readonly class ContextInvalidator
{
    public function __construct(private Outbox $outbox) {}

    /** @return list<array{bot_key: string, telegram_user_id: int}> */
    public function keysFor(int $workspaceId, int $userId): array
    {
        $botIds = TelegramBot::query()->where('workspace_id', $workspaceId)->pluck('id')->all();

        return array_values(TelegramAccount::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($workspaceId, $botIds): void {
                $query->where('workspace_id', $workspaceId);

                if ($botIds !== []) {
                    $query->orWhereIn('telegram_bot_id', $botIds);
                }
            })
            ->orderBy('id')
            ->get(['bot_key', 'telegram_user_id'])
            ->map(fn (TelegramAccount $account): array => [
                'bot_key' => $account->bot_key,
                'telegram_user_id' => $account->telegram_user_id,
            ])
            ->all());
    }

    /** @param  list<array{bot_key: string, telegram_user_id: int}>|null  $keys */
    public function invalidate(int $workspaceId, int $userId, ?array $keys = null): OutboxMessage
    {
        return $this->outbox->publish(Topics::TELEGRAM_CONTEXT_INVALIDATE, [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'keys' => $keys ?? $this->keysFor($workspaceId, $userId),
        ], $workspaceId);
    }
}
