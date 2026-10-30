<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Telegram;

use App\Billing\PlanLimits;
use App\Domain\Opsbot\DepartmentScope;
use App\Domain\Opsbot\EmployeeNotice;
use App\Domain\Opsbot\SubscriptionState;
use App\Enums\BotStatus;
use App\Enums\MemberStatus;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

final readonly class TelegramContextBuilder
{
    public function __construct(
        private DepartmentScope $scope,
        private EmployeeNotice $notice,
        private PlanLimits $limits,
        private SubscriptionState $subscriptions,
    ) {}

    public function account(string $botKey, int $telegramUserId): ?TelegramAccount
    {
        return TelegramAccount::query()
            ->where('bot_key', $botKey)
            ->where('telegram_user_id', $telegramUserId)
            ->first();
    }

    public function accountForWorkspaceUser(int $workspaceId, int $userId): ?TelegramAccount
    {
        $bot = TelegramBot::query()->where('workspace_id', $workspaceId)->first();

        if ($bot !== null && $bot->status === BotStatus::Active) {
            $account = TelegramAccount::query()->where('bot_key', $bot->key())->where('user_id', $userId)->first();

            if ($account !== null) {
                return $account;
            }
        }

        return TelegramAccount::query()
            ->where('bot_key', TelegramBot::PLATFORM_KEY)
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->first();
    }

    /** @return array{account: TelegramAccount, workspace: Workspace, membership: WorkspaceMembership}|null */
    public function resolve(TelegramAccount $account): ?array
    {
        if ($account->workspace_id === null) {
            return null;
        }

        if (! $account->isPlatform()) {
            $bot = TelegramBot::query()->find($account->telegram_bot_id);

            if ($bot === null || $bot->status !== BotStatus::Active || $bot->workspace_id !== $account->workspace_id) {
                return null;
            }
        }

        $workspace = Workspace::query()->with(['subscription', 'opsSettings', 'bot'])->find($account->workspace_id);
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('user_id', $account->user_id)
            ->first();

        if ($workspace === null || $membership === null || $membership->status === MemberStatus::Offboarded || $account->user->erased_at !== null) {
            return null;
        }

        return ['account' => $account, 'workspace' => $workspace, 'membership' => $membership];
    }

    /** @return array<string, mixed>|null */
    public function build(TelegramAccount $account): ?array
    {
        $resolved = $this->resolve($account);

        if ($resolved === null) {
            return null;
        }

        $workspace = $resolved['workspace'];
        $membership = $resolved['membership'];
        $user = $account->user;
        $role = $membership->role;
        $locale = in_array($user->locale, ['ru', 'en'], true) ? $user->locale : null;
        $version = $this->notice->version();
        $bot = $workspace->bot;
        $botUsername = $account->isPlatform()
            ? (string) config('product.telegram.bot_username')
            : $bot?->username;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->displayName(),
                'locale' => $locale,
                'kind' => $membership->kind->value,
            ],
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'plan' => $workspace->planKey(),
                'timezone' => $workspace->zone(),
                'today' => $workspace->today(),
                'subscription_state' => $this->subscriptions->for($workspace)->value,
            ],
            'role' => $role->value,
            'permissions' => $role->abilities(),
            'department_ids' => $this->scope->departmentIds($user, $workspace->id),
            'privileged' => $role->allows('knowledge.view_all'),
            'telegram' => [
                'bot_key' => $account->bot_key,
                'user_id' => $account->telegram_user_id,
                'chat_id' => $account->chat_id,
                'username' => $account->username,
                'blocked' => $account->blocked_at !== null,
            ],
            'bot' => [
                'key' => $account->bot_key,
                'username' => $botUsername !== '' ? $botUsername : null,
            ],
            'notice' => $this->notice->acknowledged($user, $workspace->id, $version)
                ? null
                : ['version' => $version, 'html' => $this->notice->shortHtml($locale ?? $workspace->locale, $workspace)],
            'limits' => [
                'ai_queries_exhausted' => $this->limits->exhausted($workspace, 'ai_queries_monthly'),
            ],
            'settings' => [
                'min_confidence' => round($workspace->settings()->min_confidence, 2),
                'ai_external_allowed' => $workspace->ai_external_allowed,
            ],
        ];
    }
}
