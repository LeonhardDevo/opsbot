<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentEventType;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $incident_id
 * @property int|null $actor_id
 * @property IncidentEventType $type
 * @property string|null $from_value
 * @property string|null $to_value
 * @property string|null $body
 * @property CarbonInterface $created_at
 * @property-read User|null $actor
 */
class IncidentEvent extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'incident_id', 'actor_id', 'type', 'from_value', 'to_value', 'body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => IncidentEventType::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class)->withoutGlobalScopes();
    }
}
