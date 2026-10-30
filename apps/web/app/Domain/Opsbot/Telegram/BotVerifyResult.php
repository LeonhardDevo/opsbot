<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Telegram;

use App\Enums\BotErrorCode;

final readonly class BotVerifyResult
{
    public function __construct(
        public bool $ok,
        public ?BotErrorCode $error = null,
        public ?int $tgBotId = null,
        public ?string $username = null,
        public ?string $firstName = null,
        public ?string $mode = null,
        public ?bool $canJoinGroups = null,
    ) {}

    public static function failed(BotErrorCode $error): self
    {
        return new self(false, $error);
    }

    /** @param  array<string, mixed>  $json */
    public static function fromResponse(array $json): self
    {
        if (($json['ok'] ?? false) !== true) {
            return self::failed(BotErrorCode::tryFrom((string) ($json['error_code'] ?? '')) ?? BotErrorCode::Network);
        }

        $mode = $json['mode'] ?? null;

        return new self(
            true,
            null,
            is_numeric($json['tg_bot_id'] ?? null) ? (int) $json['tg_bot_id'] : null,
            is_string($json['username'] ?? null) ? $json['username'] : null,
            is_string($json['first_name'] ?? null) ? $json['first_name'] : null,
            is_string($mode) && in_array($mode, ['webhook', 'polling', 'fake'], true) ? $mode : null,
            is_bool($json['can_join_groups'] ?? null) ? $json['can_join_groups'] : null,
        );
    }
}
