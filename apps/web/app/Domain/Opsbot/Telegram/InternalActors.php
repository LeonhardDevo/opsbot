<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Telegram;

use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Access\ActorFactory;
use App\Domain\Opsbot\OpsbotException;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Models\User;
use App\Models\Workspace;

final readonly class InternalActors
{
    public function __construct(
        private TelegramContextBuilder $contexts,
        private ActorFactory $actors,
    ) {}

    /**
     * @return array{actor: Actor, account: TelegramAccount}
     *
     * @throws OpsbotException
     */
    public function fromTelegram(mixed $botKey, mixed $telegramUserId): array
    {
        if (! is_string($botKey) || ! TelegramBot::isValidKey($botKey) || ! is_numeric($telegramUserId)) {
            throw new OpsbotException('internal.invalid_identity');
        }

        $account = $this->contexts->account($botKey, (int) $telegramUserId);
        $resolved = $account !== null ? $this->contexts->resolve($account) : null;

        if ($account === null || $resolved === null) {
            throw OpsbotException::notFound('telegram.not_linked');
        }

        $actor = $this->actors->forUser($account->user, $resolved['workspace']);

        if ($actor === null) {
            throw OpsbotException::notFound('telegram.not_linked');
        }

        return ['actor' => $actor, 'account' => $account];
    }

    /** @throws OpsbotException */
    public function fromWorkspaceUser(mixed $workspaceId, mixed $userId): Actor
    {
        if (! is_numeric($workspaceId) || ! is_numeric($userId)) {
            throw new OpsbotException('internal.invalid_identity');
        }

        $workspace = Workspace::query()->find((int) $workspaceId);
        $user = User::query()->find((int) $userId);
        $actor = $workspace !== null && $user !== null ? $this->actors->forUser($user, $workspace) : null;

        if ($actor === null) {
            throw OpsbotException::notFound('telegram.not_linked');
        }

        return $actor;
    }
}
