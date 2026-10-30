<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Models\TelegramBot;

final class TelegramLinkRequest extends InternalRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'bot_key' => ['required', 'string', 'regex:'.TelegramBot::KEY_PATTERN],
            'token' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'telegram_user_id' => ['required', 'integer', 'min:1'],
            'chat_id' => ['required', 'integer'],
            'username' => ['nullable', 'string', 'max:64'],
            'language_code' => ['nullable', 'string', 'max:16'],
        ];
    }
}
