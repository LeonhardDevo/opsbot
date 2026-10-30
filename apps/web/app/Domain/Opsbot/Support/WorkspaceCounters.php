<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Support;

use App\Support\DbRow;
use Illuminate\Support\Facades\DB;

final class WorkspaceCounters
{
    public const string INCIDENT = 'incident';

    public const string TASK = 'task';

    public const string QUESTION = 'question';

    public function next(int $workspaceId, string $key): int
    {
        $row = DB::selectOne(
            'INSERT INTO workspace_counters (workspace_id, key, value) VALUES (?, ?, 1)
             ON CONFLICT (workspace_id, key) DO UPDATE SET value = workspace_counters.value + 1
             RETURNING value',
            [$workspaceId, $key],
        );

        return DbRow::int($row, 'value');
    }
}
