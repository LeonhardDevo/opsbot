<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Support\DbRow;
use Illuminate\Support\Facades\DB;

final class SeatCounter
{
    public function used(int $workspaceId): int
    {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT
                  (SELECT count(*) FROM workspace_user wu
                     WHERE wu.workspace_id = :workspace_id AND wu.status IN ('invited', 'active'))
                + (SELECT count(*) FROM workspace_invitations wi
                     WHERE wi.workspace_id = :workspace_id_inv AND wi.accepted_at IS NULL AND wi.expires_at > now())
                AS seats_used
            SQL,
            ['workspace_id' => $workspaceId, 'workspace_id_inv' => $workspaceId],
        );

        return DbRow::int($row, 'seats_used');
    }

    /** @return array{web_members: int, employees_active: int, employees_invited: int} */
    public function breakdown(int $workspaceId): array
    {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT
                  count(*) FILTER (WHERE kind = 'web' AND status IN ('invited', 'active')) AS web_members,
                  count(*) FILTER (WHERE kind = 'telegram_only' AND status = 'active') AS employees_active,
                  count(*) FILTER (WHERE kind = 'telegram_only' AND status = 'invited') AS employees_invited
                FROM workspace_user WHERE workspace_id = ?
            SQL,
            [$workspaceId],
        );

        return [
            'web_members' => DbRow::int($row, 'web_members'),
            'employees_active' => DbRow::int($row, 'employees_active'),
            'employees_invited' => DbRow::int($row, 'employees_invited'),
        ];
    }
}
