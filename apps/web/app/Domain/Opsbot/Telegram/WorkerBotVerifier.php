<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Telegram;

use App\Enums\BotErrorCode;
use App\Internal\InternalClient;
use App\Models\TelegramBot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

final readonly class WorkerBotVerifier
{
    public function __construct(private InternalClient $internal) {}

    public function verify(TelegramBot $bot): BotVerifyResult
    {
        try {
            $response = $this->internal->to('worker')
                ->timeout((int) config('opsbot.telegram.verify_timeout', 15))
                ->post('/internal/v1/telegram/bots/'.$bot->id.'/verify', []);
        } catch (ConnectionException) {
            Log::warning('telegram_bot.verify_unreachable', ['bot_id' => $bot->id]);

            return BotVerifyResult::failed(BotErrorCode::Network);
        }

        $json = $response->json();

        if (! is_array($json)) {
            return BotVerifyResult::failed(BotErrorCode::Network);
        }

        if ($response->failed() && ! array_key_exists('error_code', $json)) {
            Log::warning('telegram_bot.verify_failed', ['bot_id' => $bot->id, 'status' => $response->status()]);

            return BotVerifyResult::failed(BotErrorCode::Network);
        }

        return BotVerifyResult::fromResponse($json);
    }
}
