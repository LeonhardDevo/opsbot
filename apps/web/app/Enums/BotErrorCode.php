<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum BotErrorCode: string
{
    use EnumValues;

    case InvalidToken = 'invalid_token';
    case TokenMismatch = 'token_mismatch';
    case AlreadyConnected = 'already_connected';
    case Network = 'network';
    case WebhookFailed = 'webhook_failed';
    case Revoked = 'revoked';

    public static function labelPrefix(): string
    {
        return 'bot.errors';
    }
}
