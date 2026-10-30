<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Audit\Auditor;
use App\Domain\Opsbot\Notifications\OpsNotifier;
use App\Domain\Opsbot\Telegram\BotVerifyResult;
use App\Domain\Opsbot\Telegram\ContextInvalidator;
use App\Domain\Opsbot\Telegram\WorkerBotVerifier;
use App\Enums\BotErrorCode;
use App\Enums\BotStatus;
use App\Enums\WorkspaceRole;
use App\Models\EmployeeLink;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Models\User;
use App\Models\Workspace;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class TelegramBotService
{
    public function __construct(
        private WorkerBotVerifier $verifier,
        private Auditor $auditor,
        private Outbox $outbox,
        private ContextInvalidator $invalidator,
        private OpsNotifier $notifier,
    ) {}

    public static function validToken(string $token): bool
    {
        return preg_match((string) config('opsbot.telegram.token_pattern'), $token) === 1;
    }

    public function activeBot(Workspace $workspace): ?TelegramBot
    {
        $bot = TelegramBot::query()->where('workspace_id', $workspace->id)->first();

        return $bot !== null && $bot->isActive() ? $bot : null;
    }

    public static function platformUsername(): ?string
    {
        $username = config('product.telegram.bot_username');

        return is_string($username) && $username !== '' ? ltrim($username, '@') : null;
    }

    /** @return array{key: string, username: string}|null */
    public function deliveryBot(Workspace $workspace): ?array
    {
        $bot = $this->activeBot($workspace);

        if ($bot !== null && $bot->username !== null) {
            return ['key' => $bot->key(), 'username' => $bot->username];
        }

        $platform = self::platformUsername();

        return $platform !== null ? ['key' => TelegramBot::PLATFORM_KEY, 'username' => $platform] : null;
    }

    /** @throws OpsbotException */
    public function connect(Workspace $workspace, User $actor, string $token): BotVerifyResult
    {
        if (! self::validToken($token)) {
            throw new OpsbotException('bot.invalid_token');
        }

        $tgBotId = TelegramBot::botIdFromToken($token);

        $conflict = TelegramBot::query()
            ->where('tg_bot_id', $tgBotId)
            ->where('workspace_id', '!=', $workspace->id)
            ->where('status', '!=', BotStatus::Disconnected->value)
            ->exists();

        if ($conflict) {
            throw new OpsbotException('bot.already_connected');
        }

        [$bot, $previousBotId, $rotation] = DB::transaction(function () use ($workspace, $actor, $token, $tgBotId): array {
            $bot = TelegramBot::query()->where('workspace_id', $workspace->id)->lockForUpdate()->first();

            if ($bot !== null && $bot->status === BotStatus::Disconnecting) {
                throw new OpsbotException('bot.disconnecting');
            }

            $previousBotId = $bot !== null && $bot->status !== BotStatus::Disconnected ? $bot->tg_bot_id : null;
            $rotation = $previousBotId !== null;

            $bot ??= new TelegramBot(['workspace_id' => $workspace->id]);

            if ($rotation && $previousBotId !== $tgBotId) {
                $this->dropLinks($bot);
                $bot->username = null;
                $bot->first_name = null;
            }

            $bot->fill([
                'tg_bot_id' => $tgBotId,
                'token' => $token,
                'webhook_secret' => self::newWebhookSecret(),
                'status' => BotStatus::Connecting,
                'last_error' => null,
                'last_error_at' => null,
                'connected_by' => $actor->id,
                'disconnected_at' => null,
            ]);
            $bot->save();

            return [$bot, $previousBotId, $rotation];
        });

        $result = $this->verifier->verify($bot);

        DB::transaction(function () use ($bot, $result, $actor, $previousBotId, $rotation, $tgBotId): void {
            $bot->refresh();

            if ($result->ok) {
                if ($result->tgBotId !== null && $result->tgBotId !== $tgBotId) {
                    $this->markFailed($bot, BotErrorCode::TokenMismatch, BotStatus::InvalidToken);

                    return;
                }

                $bot->fill([
                    'status' => BotStatus::Active,
                    'username' => $result->username ?? $bot->username,
                    'first_name' => $result->firstName ?? $bot->first_name,
                    'mode' => $result->mode,
                    'can_join_groups' => $result->canJoinGroups,
                    'last_error' => null,
                    'last_error_at' => null,
                    'last_seen_at' => now(),
                    'connected_at' => $rotation && $previousBotId === $tgBotId ? ($bot->connected_at ?? now()) : now(),
                ]);
                $bot->save();

                $sameBotRotation = $rotation && $previousBotId === $tgBotId;

                $this->auditor->record(
                    $sameBotRotation ? 'telegram_bot.token_rotated' : 'telegram_bot.connected',
                    $bot,
                    metadata: array_filter([
                        'tg_bot_id' => $bot->tg_bot_id,
                        'username' => $bot->username,
                        'mode' => $bot->mode,
                        'replaced_tg_bot_id' => $rotation && ! $sameBotRotation ? $previousBotId : null,
                    ], fn (mixed $value): bool => $value !== null),
                    actor: $actor,
                    workspace: $bot->workspace_id,
                    subjectLabel: $bot->username !== null ? '@'.$bot->username : (string) $bot->tg_bot_id,
                );

                return;
            }

            $error = $result->error ?? BotErrorCode::Network;
            $status = in_array($error, [BotErrorCode::InvalidToken, BotErrorCode::TokenMismatch], true)
                ? BotStatus::InvalidToken
                : BotStatus::Connecting;

            $this->markFailed($bot, $error, $status);
        });

        return $result->ok && $bot->fresh()?->status === BotStatus::Active
            ? $result
            : BotVerifyResult::failed($bot->fresh()->last_error ?? BotErrorCode::Network);
    }

    /** @throws OpsbotException */
    public function disconnect(Workspace $workspace, User $actor): TelegramBot
    {
        return DB::transaction(function () use ($workspace, $actor): TelegramBot {
            $bot = TelegramBot::query()->where('workspace_id', $workspace->id)->lockForUpdate()->first();

            if ($bot === null || in_array($bot->status, [BotStatus::Disconnected, BotStatus::Disconnecting], true)) {
                throw new OpsbotException('bot.not_connected');
            }

            $bot->status = BotStatus::Disconnecting;
            $bot->save();

            $this->outbox->publish(Topics::TELEGRAM_BOT_DISCONNECT, [
                'bot_key' => $bot->key(),
                'telegram_bot_id' => $bot->id,
                'workspace_id' => $workspace->id,
            ], $workspace->id);

            $this->auditor->record(
                'telegram_bot.disconnect_requested',
                $bot,
                metadata: ['tg_bot_id' => $bot->tg_bot_id],
                actor: $actor,
                workspace: $workspace,
                subjectLabel: $bot->username !== null ? '@'.$bot->username : (string) $bot->tg_bot_id,
            );

            return $bot;
        });
    }

    public function applyStatus(TelegramBot $bot, string $status, ?string $errorCode, ?CarbonInterface $seenAt): void
    {
        DB::transaction(function () use ($bot, $status, $errorCode, $seenAt): void {
            $bot = TelegramBot::query()->whereKey($bot->id)->lockForUpdate()->firstOrFail();

            match ($status) {
                'disconnected' => $this->finalize($bot, null),
                'revoked' => $this->markRevoked($bot, $errorCode),
                default => $this->markSeen($bot, $seenAt),
            };
        });
    }

    public function reconcile(?CarbonInterface $now = null): int
    {
        $threshold = ($now ?? now())->copy()->subMinutes((int) config('opsbot.telegram.disconnect_finalize_after_minutes', 60));
        $finalized = 0;

        $stuck = TelegramBot::query()
            ->where('status', BotStatus::Disconnecting->value)
            ->where('updated_at', '<=', $threshold)
            ->pluck('id');

        foreach ($stuck as $id) {
            DB::transaction(function () use ($id, &$finalized): void {
                $bot = TelegramBot::query()->whereKey($id)->lockForUpdate()->first();

                if ($bot !== null && $bot->status === BotStatus::Disconnecting) {
                    Log::warning('telegram_bot.finalized_by_reconcile', ['bot_id' => $bot->id]);
                    $this->finalize($bot, null);
                    $finalized++;
                }
            });
        }

        return $finalized;
    }

    private function finalize(TelegramBot $bot, ?User $actor): void
    {
        if ($bot->status === BotStatus::Disconnected) {
            return;
        }

        $this->dropLinks($bot);

        $bot->forceFill([
            'status' => BotStatus::Disconnected,
            'token' => null,
            'webhook_secret' => null,
            'disconnected_at' => now(),
        ])->save();

        $this->auditor->record(
            'telegram_bot.disconnected',
            $bot,
            metadata: ['tg_bot_id' => $bot->tg_bot_id],
            actor: $actor,
            workspace: $bot->workspace_id,
            subjectLabel: $bot->username !== null ? '@'.$bot->username : (string) $bot->tg_bot_id,
            source: Auditor::SOURCE_SYSTEM,
        );
    }

    private function markRevoked(TelegramBot $bot, ?string $errorCode): void
    {
        if ($bot->status !== BotStatus::Active) {
            return;
        }

        $bot->forceFill([
            'status' => BotStatus::Revoked,
            'last_error' => BotErrorCode::tryFrom((string) $errorCode) ?? BotErrorCode::Revoked,
            'last_error_at' => now(),
        ])->save();

        $this->auditor->record(
            'telegram_bot.revoked',
            $bot,
            metadata: ['tg_bot_id' => $bot->tg_bot_id],
            actor: null,
            workspace: $bot->workspace_id,
            subjectLabel: $bot->username !== null ? '@'.$bot->username : (string) $bot->tg_bot_id,
            source: Auditor::SOURCE_SYSTEM,
        );

        $workspace = $bot->workspace;
        $admins = $workspace->members()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->wherePivot('status', 'active')
            ->get();

        foreach ($admins as $admin) {
            $this->notifier->notify(Topics::BOT_REVOKED, $admin, $workspace->id, fn (): array => [
                'bot_username' => $bot->username,
                'web_url' => OpsNotifier::url('/bot'),
            ]);
        }
    }

    private function markSeen(TelegramBot $bot, ?CarbonInterface $seenAt): void
    {
        if (! in_array($bot->status, [BotStatus::Active, BotStatus::Connecting], true)) {
            return;
        }

        $bot->forceFill(['last_seen_at' => $seenAt ?? now()])->save();
    }

    private function markFailed(TelegramBot $bot, BotErrorCode $error, BotStatus $status): void
    {
        $bot->forceFill([
            'status' => $status,
            'last_error' => $error,
            'last_error_at' => now(),
        ])->save();
    }

    private function dropLinks(TelegramBot $bot): void
    {
        if (! $bot->exists) {
            return;
        }

        $accounts = TelegramAccount::query()->where('telegram_bot_id', $bot->id)->get();

        foreach ($accounts->groupBy('user_id') as $userId => $userAccounts) {
            $keys = array_values($userAccounts->map(fn (TelegramAccount $account): array => [
                'bot_key' => $account->bot_key,
                'telegram_user_id' => $account->telegram_user_id,
            ])->all());

            TelegramAccount::query()->whereIn('id', $userAccounts->pluck('id'))->delete();
            $this->invalidator->invalidate($bot->workspace_id, (int) $userId, $keys);
        }

        EmployeeLink::query()
            ->where('bot_key', $bot->key())
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private static function newWebhookSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
        $secret = '';

        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return Str::of($secret)->toString();
    }
}
