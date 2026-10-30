<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Domain\Opsbot\TelegramBotService;
use App\Enums\BotStatus;
use App\Http\Controllers\Controller;
use App\Models\TelegramBot;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

final class TelegramBotsController extends Controller
{
    public function active(): JsonResponse
    {
        $bots = TelegramBot::query()
            ->whereIn('status', [BotStatus::Active->value, BotStatus::Connecting->value, BotStatus::Disconnecting->value])
            ->orderBy('id')
            ->get();

        return response()->json([
            'bots' => $bots->map(fn (TelegramBot $bot): array => [
                'bot_key' => $bot->key(),
                'status' => $bot->status->value,
                'mode' => $bot->mode,
            ])->values()->all(),
        ]);
    }

    public function credentials(int $bot): JsonResponse
    {
        $model = TelegramBot::query()->find($bot);

        if ($model === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        if (! in_array($model->status, [BotStatus::Connecting, BotStatus::Active, BotStatus::Revoked, BotStatus::Disconnecting], true) || $model->token === null) {
            return response()->json(['error' => 'gone'], 410);
        }

        Log::info('telegram_bot.credentials_served', ['bot_id' => $model->id, 'status' => $model->status->value]);

        return response()->json([
            'bot_key' => $model->key(),
            'tg_bot_id' => $model->tg_bot_id,
            'username' => $model->username,
            'token' => $model->token,
            'webhook_secret' => $model->webhook_secret,
            'status' => $model->status->value,
        ]);
    }

    public function status(Request $request, int $bot, TelegramBotService $bots): Response|JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,revoked,disconnected'],
            'error_code' => ['nullable', 'string', 'max:32'],
            'seen_at' => ['nullable', 'date'],
        ]);

        $model = TelegramBot::query()->find($bot);

        if ($model === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $bots->applyStatus(
            $model,
            (string) $data['status'],
            isset($data['error_code']) ? (string) $data['error_code'] : null,
            isset($data['seen_at']) ? CarbonImmutable::parse((string) $data['seen_at']) : null,
        );

        return response()->noContent();
    }
}
