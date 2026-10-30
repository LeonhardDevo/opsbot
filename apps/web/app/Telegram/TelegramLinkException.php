<?php

declare(strict_types=1);

namespace App\Telegram;

use RuntimeException;

final class TelegramLinkException extends RuntimeException
{
    /** @param  'invalid_token'|'token_used'|'token_expired'|'already_linked'|'wrong_bot'|'bot_inactive'  $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Telegram link failed: {$reason}");
    }

    public function status(): int
    {
        return match ($this->reason) {
            'invalid_token', 'bot_inactive' => 404,
            'already_linked' => 409,
            'wrong_bot' => 422,
            default => 410,
        };
    }

    public function error(): string
    {
        return match ($this->reason) {
            'already_linked' => 'conflict',
            default => $this->reason,
        };
    }
}
