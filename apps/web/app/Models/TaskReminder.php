<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReminderKind;
use App\Enums\ReminderStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $task_id
 * @property ReminderKind $kind
 * @property CarbonInterface $planned_at
 * @property ReminderStatus $status
 * @property string|null $skip_reason
 * @property CarbonInterface|null $sent_at
 * @property string|null $outbox_id
 * @property-read Task $task
 */
class TaskReminder extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $fillable = ['workspace_id', 'task_id', 'kind', 'planned_at', 'status', 'skip_reason', 'sent_at', 'outbox_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'status' => ReminderStatus::class,
            'planned_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withoutGlobalScopes();
    }
}
