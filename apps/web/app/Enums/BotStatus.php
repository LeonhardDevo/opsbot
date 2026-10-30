<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum BotStatus: string
{
    use EnumValues;

    case Connecting = 'connecting';
    case Active = 'active';
    case InvalidToken = 'invalid_token';
    case Revoked = 'revoked';
    case Disconnecting = 'disconnecting';
    case Disconnected = 'disconnected';

    public static function labelPrefix(): string
    {
        return 'bot.status';
    }
}
