<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $workspace_id
 * @property int $ai_query_id
 * @property int|null $user_id
 * @property ActionType $type
 * @property string $label
 * @property array<string, mixed> $payload
 * @property ActionStatus $status
 * @property string|null $result_type
 * @property int|null $result_id
 * @property string|null $error_code
 * @property CarbonInterface|null $executed_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface $created_at
 * @property-read AiQuery $aiQuery
 * @property-read User|null $user
 */
class AiQueryAction extends Model
{
    use BelongsToWorkspace;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'ai_query_id', 'user_id', 'type', 'label', 'payload', 'status', 'result_type', 'result_id',
        'error_code', 'executed_at', 'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ActionType::class,
            'status' => ActionStatus::class,
            'payload' => 'array',
            'result_id' => 'integer',
            'executed_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiQuery, $this> */
    public function aiQuery(): BelongsTo
    {
        return $this->belongsTo(AiQuery::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
