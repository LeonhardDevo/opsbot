<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Audit\Auditor;
use App\Domain\Opsbot\Telegram\ContextInvalidator;
use App\Domain\Opsbot\TelegramBotService;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TelegramLinker
{
    public function __construct(
        private Auditor $auditor,
        private TelegramBotService $bots,
        private ContextInvalidator $invalidator,
    ) {}

    /** @return array{url: string, token: string, expires_at: CarbonInterface, bot_key: string, bot_username: string} */
    public function issueLink(User $user, Workspace $workspace): array
    {
        $bot = $this->bots->deliveryBot($workspace);

        if ($bot === null) {
            throw new \RuntimeException('No Telegram bot is available for this workspace.');
        }

        $token = Str::random(40);
        $expiresAt = now()->addMinutes((int) config('product.telegram.link_ttl_minutes', 15));

        DB::transaction(function () use ($user, $workspace, $token, $expiresAt, $bot): void {
            TelegramLinkToken::query()->where('user_id', $user->id)->whereNull('used_at')->delete();

            TelegramLinkToken::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'bot_key' => $bot['key'],
                'token_hash' => TelegramLinkToken::hashToken($token),
                'expires_at' => $expiresAt,
            ]);
        });

        return [
            'url' => 'https://t.me/'.$bot['username'].'?start='.$token,
            'token' => $token,
            'expires_at' => $expiresAt,
            'bot_key' => $bot['key'],
            'bot_username' => $bot['username'],
        ];
    }

    /**
     * @param  array{telegram_user_id: int, chat_id: int, username?: string|null, language_code?: string|null}  $identity
     *
     * @throws TelegramLinkException
     */
    public function link(string $botKey, string $token, array $identity): TelegramAccount
    {
        $account = DB::transaction(function () use ($botKey, $token, $identity): TelegramAccount {
            $linkToken = TelegramLinkToken::query()
                ->where('token_hash', TelegramLinkToken::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($linkToken === null) {
                throw new TelegramLinkException('invalid_token');
            }

            if ($linkToken->bot_key !== $botKey) {
                throw new TelegramLinkException('wrong_bot');
            }

            if (! $linkToken->isUsable()) {
                throw new TelegramLinkException($linkToken->used_at !== null ? 'token_used' : 'token_expired');
            }

            $user = $linkToken->user;
            $workspaceId = $linkToken->workspace_id;

            if ($workspaceId === null || ! $user->belongsToWorkspace($workspaceId)) {
                throw new TelegramLinkException('token_expired');
            }

            $bot = $botKey === TelegramBot::PLATFORM_KEY ? null : TelegramBot::query()->find((int) $botKey);

            if ($botKey !== TelegramBot::PLATFORM_KEY && ($bot === null || ! $bot->isActive() || $bot->workspace_id !== $workspaceId)) {
                throw new TelegramLinkException('bot_inactive');
            }

            $linkedElsewhere = TelegramAccount::query()
                ->where('bot_key', $botKey)
                ->where('telegram_user_id', $identity['telegram_user_id'])
                ->where('user_id', '!=', $user->id)
                ->exists();

            if ($linkedElsewhere) {
                throw new TelegramLinkException('already_linked');
            }

            $linkToken->forceFill(['used_at' => now()])->save();

            $account = TelegramAccount::query()->updateOrCreate(
                ['bot_key' => $botKey, 'user_id' => $user->id],
                [
                    'workspace_id' => $workspaceId,
                    'telegram_bot_id' => $bot?->id,
                    'telegram_user_id' => $identity['telegram_user_id'],
                    'chat_id' => $identity['chat_id'],
                    'username' => $identity['username'] ?? null,
                    'language_code' => $identity['language_code'] ?? null,
                    'linked_at' => now(),
                    'blocked_at' => null,
                ],
            );

            $this->auditor->record(
                'telegram.linked',
                $account,
                metadata: ['username' => $account->username, 'bot_key' => $botKey],
                actor: $user,
                workspace: $workspaceId,
                subjectLabel: $account->username !== null ? '@'.$account->username : (string) $account->telegram_user_id,
                source: Auditor::SOURCE_BOT,
            );

            return $account;
        });

        if ($account->workspace_id !== null) {
            $this->invalidator->invalidate($account->workspace_id, $account->user_id);
        }

        return $account;
    }

    public function unlinkAccount(TelegramAccount $account, ?User $actor, string $source): void
    {
        DB::transaction(function () use ($account, $actor, $source): void {
            $keys = [['bot_key' => $account->bot_key, 'telegram_user_id' => $account->telegram_user_id]];

            $this->auditor->record(
                'telegram.unlinked',
                $account,
                metadata: ['username' => $account->username, 'bot_key' => $account->bot_key],
                actor: $actor ?? $account->user,
                workspace: $account->workspace_id,
                subjectLabel: $account->username !== null ? '@'.$account->username : (string) $account->telegram_user_id,
                source: $source,
            );

            $account->delete();

            if ($account->workspace_id !== null) {
                $this->invalidator->invalidate($account->workspace_id, $account->user_id, $keys);
            }
        });
    }

    public function unlink(User $user, ?Workspace $workspace = null): bool
    {
        $accounts = $user->telegramAccounts()
            ->when($workspace !== null, fn ($query) => $query->where('workspace_id', $workspace?->id))
            ->get();

        foreach ($accounts as $account) {
            $this->unlinkAccount($account, $user, Auditor::SOURCE_WEB);
        }

        $user->unsetRelation('telegramAccount');
        $user->unsetRelation('telegramAccounts');

        return $accounts->isNotEmpty();
    }
}
