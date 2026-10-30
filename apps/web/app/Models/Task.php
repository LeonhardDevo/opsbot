<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $number
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property CarbonInterface|null $due_at
 * @property int|null $assignee_id
 * @property int|null $created_by
 * @property int|null $department_id
 * @property int|null $incident_id
 * @property int|null $ai_query_id
 * @property TaskSource $source
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read User|null $assignee
 * @property-read User|null $creator
 * @property-read Department|null $department
 * @property-read Incident|null $incident
 * @property-read Collection<int, TaskReminder> $reminders
 */
class Task extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'number', 'title', 'description', 'status', 'due_at', 'assignee_id', 'created_by',
        'department_id', 'incident_id', 'ai_query_id', 'source', 'completed_at', 'cancelled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'source' => TaskSource::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class)->withoutGlobalScopes();
    }

    /** @return HasMany<TaskReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(TaskReminder::class)->withoutGlobalScopes();
    }

    public function isOpen(): bool
    {
        return $this->status === TaskStatus::Todo || $this->status === TaskStatus::InProgress;
    }

    public function isOverdue(?CarbonInterface $now = null): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->lt($now ?? now());
    }
}
