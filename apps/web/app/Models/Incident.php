<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentCategory;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $number
 * @property IncidentCategory $category
 * @property Severity $severity
 * @property IncidentStatus $status
 * @property string $title
 * @property string $description
 * @property string|null $client_ref
 * @property int|null $department_id
 * @property int|null $reporter_id
 * @property int|null $assignee_id
 * @property IncidentSource $source
 * @property int|null $ai_query_id
 * @property string|null $ai_query_action_id
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $closed_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property int|null $tasks_count
 * @property-read Department|null $department
 * @property-read User|null $reporter
 * @property-read User|null $assignee
 * @property-read AiQuery|null $aiQuery
 */
class Incident extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'number', 'category', 'severity', 'status', 'title', 'description', 'client_ref',
        'department_id', 'reporter_id', 'assignee_id', 'source', 'ai_query_id', 'ai_query_action_id',
        'resolved_at', 'closed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => IncidentCategory::class,
            'severity' => Severity::class,
            'status' => IncidentStatus::class,
            'source' => IncidentSource::class,
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<AiQuery, $this> */
    public function aiQuery(): BelongsTo
    {
        return $this->belongsTo(AiQuery::class)->withoutGlobalScopes();
    }

    /** @return HasMany<IncidentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class)->withoutGlobalScopes();
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->withoutGlobalScopes();
    }
}
