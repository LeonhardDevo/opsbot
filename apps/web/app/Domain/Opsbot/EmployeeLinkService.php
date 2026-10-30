<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Audit\Auditor;
use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Telegram\ContextInvalidator;
use App\Enums\MemberStatus;
use App\Models\EmployeeLink;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Telegram\TelegramLinkException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EmployeeLinkService
{
    public function __construct(
        private TelegramBotService $bots,
        private DepartmentScope $scope,
        private Auditor $auditor,
        private ContextInvalidator $invalidator,
    ) {}

    /**
     * @return array{user_id: int, url: string, expires_at: string, bot_username: string}
     *
     * @throws OpsbotException
     */
    public function issue(Actor $actor, User $employee): array
    {
        $this->assertManageable($actor, $employee);

        return $this->issueFor($actor->workspace, $employee, $actor->user);
    }

    /**
     * @param  list<int>  $userIds
     * @return list<array{user_id: int, url: string, expires_at: string, bot_username: string}>
     *
     * @throws OpsbotException
     */
    public function issueBulk(Actor $actor, array $userIds): array
    {
        $users = User::query()->whereIn('id', $userIds)->orderBy('name')->orderBy('id')->get();

        if ($users->count() !== count(array_unique($userIds))) {
            throw OpsbotException::notFound('employees.not_found');
        }

        foreach ($users as $user) {
            $this->assertManageable($actor, $user);
        }

        return array_values($users->map(fn (User $user): array => $this->issueFor($actor->workspace, $user, $actor->user))->all());
    }

    /**
     * @return array{user_id: int, url: string, expires_at: string, bot_username: string}
     *
     * @throws OpsbotException
     */
    public function issueFor(Workspace $workspace, User $employee, ?User $issuer): array
    {
        $bot = $this->bots->deliveryBot($workspace);

        if ($bot === null) {
            throw new OpsbotException('bot.not_connected');
        }

        $membership = $employee->membershipIn($workspace);

        if ($membership === null || $membership->status === MemberStatus::Offboarded) {
            throw new OpsbotException('employees.not_active');
        }

        $token = Str::random(40);
        $expiresAt = now()->addDays((int) config('opsbot.employee_links.ttl_days', 7));

        DB::transaction(function () use ($workspace, $employee, $issuer, $bot, $token, $expiresAt): void {
            EmployeeLink::query()
                ->where('user_id', $employee->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            EmployeeLink::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $employee->id,
                'bot_key' => $bot['key'],
                'token_hash' => EmployeeLink::hashToken($token),
                'expires_at' => $expiresAt,
                'created_by' => $issuer?->id,
            ]);

            $this->auditor->record(
                'employee.link_issued',
                $employee,
                metadata: ['bot_key' => $bot['key'], 'expires_at' => $expiresAt->toIso8601String()],
                actor: $issuer,
                workspace: $workspace,
                subjectLabel: $employee->displayName(),
            );
        });

        return [
            'user_id' => $employee->id,
            'url' => 'https://t.me/'.$bot['username'].'?start='.EmployeeLink::PREFIX.$token,
            'expires_at' => $expiresAt->toIso8601String(),
            'bot_username' => $bot['username'],
        ];
    }

    /**
     * @param  array{telegram_user_id: int, chat_id: int, username?: string|null, language_code?: string|null}  $identity
     * @return array{account: TelegramAccount, user: User, workspace: Workspace}
     *
     * @throws TelegramLinkException
     */
    public function redeem(string $botKey, string $token, array $identity): array
    {
        $result = DB::transaction(function () use ($botKey, $token, $identity): array {
            $link = EmployeeLink::query()
                ->where('token_hash', EmployeeLink::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($link === null) {
                throw new TelegramLinkException('invalid_token');
            }

            if ($link->bot_key !== $botKey) {
                throw new TelegramLinkException('wrong_bot');
            }

            if ($link->used_at !== null) {
                throw new TelegramLinkException('token_used');
            }

            if ($link->revoked_at !== null || ! $link->expires_at->isFuture()) {
                throw new TelegramLinkException('token_expired');
            }

            $bot = $botKey === TelegramBot::PLATFORM_KEY ? null : TelegramBot::query()->find((int) $botKey);

            if ($botKey !== TelegramBot::PLATFORM_KEY && ($bot === null || ! $bot->isActive() || $bot->workspace_id !== $link->workspace_id)) {
                throw new TelegramLinkException('bot_inactive');
            }

            $membership = WorkspaceMembership::query()
                ->where('workspace_id', $link->workspace_id)
                ->where('user_id', $link->user_id)
                ->lockForUpdate()
                ->first();

            if ($membership === null || $membership->status === MemberStatus::Offboarded) {
                throw new TelegramLinkException('token_expired');
            }

            $conflict = TelegramAccount::query()
                ->where('bot_key', $botKey)
                ->where('telegram_user_id', $identity['telegram_user_id'])
                ->where('user_id', '!=', $link->user_id)
                ->exists();

            if ($conflict) {
                throw new TelegramLinkException('already_linked');
            }

            $account = TelegramAccount::query()->updateOrCreate(
                ['bot_key' => $botKey, 'user_id' => $link->user_id],
                [
                    'workspace_id' => $link->workspace_id,
                    'telegram_bot_id' => $bot?->id,
                    'telegram_user_id' => $identity['telegram_user_id'],
                    'chat_id' => $identity['chat_id'],
                    'username' => $identity['username'] ?? null,
                    'language_code' => $identity['language_code'] ?? null,
                    'linked_at' => now(),
                    'blocked_at' => null,
                ],
            );

            $link->forceFill(['used_at' => now()])->save();

            if ($membership->status === MemberStatus::Invited) {
                WorkspaceMembership::query()->whereKey($membership->id)->update(['status' => MemberStatus::Active->value]);
            }

            $user = $link->user;

            $this->auditor->record(
                'employee.linked',
                $user,
                metadata: ['bot_key' => $botKey, 'username' => $account->username],
                actor: $user,
                workspace: $link->workspace_id,
                subjectLabel: $user->displayName(),
                source: Auditor::SOURCE_BOT,
            );

            return ['account' => $account, 'user' => $user, 'workspace' => $link->workspace];
        });

        $this->invalidator->invalidate($result['workspace']->id, $result['user']->id);

        return $result;
    }

    /** @throws OpsbotException */
    private function assertManageable(Actor $actor, User $employee): void
    {
        if (! $this->scope->canManageEmployee($actor, $employee)) {
            throw OpsbotException::forbidden();
        }
    }
}
