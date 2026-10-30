<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Presenters;

use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Support\DbRow;
use Illuminate\Support\Facades\DB;

final class BotPresenter
{
    /** @return array<string, mixed>|null */
    public static function present(?TelegramBot $bot): ?array
    {
        if ($bot === null) {
            return null;
        }

        return [
            'id' => $bot->id,
            'key' => $bot->key(),
            'status' => $bot->status->value,
            'username' => $bot->username,
            'first_name' => $bot->first_name,
            'tg_bot_id' => $bot->tg_bot_id,
            'mode' => $bot->mode,
            'can_join_groups' => $bot->can_join_groups,
            'last_error' => $bot->last_error?->value,
            'last_seen_at' => $bot->last_seen_at?->toIso8601String(),
            'connected_at' => $bot->connected_at?->toIso8601String(),
            'connected_by' => Refs::user($bot->connector),
            'token_hint' => $bot->tokenHint(),
            'linked_count' => TelegramAccount::query()->where('telegram_bot_id', $bot->id)->count(),
            'pending_relink_count' => self::pendingRelink($bot->workspace_id, $bot->isActive() ? $bot->key() : null),
        ];
    }

    /** @return array{key: string, username: string|null, status: string}|null */
    public static function shared(?TelegramBot $bot): ?array
    {
        if ($bot === null) {
            return null;
        }

        return ['key' => $bot->key(), 'username' => $bot->username, 'status' => $bot->status->value];
    }

    public static function pendingRelink(int $workspaceId, ?string $botKey): int
    {
        $row = DB::selectOne(
            'SELECT count(*) AS total FROM workspace_user wu
             WHERE wu.workspace_id = ? AND wu.kind = ? AND wu.status = ?
               AND NOT EXISTS (SELECT 1 FROM telegram_accounts ta WHERE ta.user_id = wu.user_id AND ta.bot_key = ?)',
            [$workspaceId, MemberKind::TelegramOnly->value, MemberStatus::Active->value, $botKey ?? ''],
        );

        return DbRow::int($row, 'total');
    }
}
