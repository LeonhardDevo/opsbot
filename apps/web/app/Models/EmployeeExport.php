<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $workspace_id
 * @property int $subject_user_id
 * @property int|null $requested_by
 * @property string $status
 * @property string|null $disk
 * @property string|null $path
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface $created_at
 * @property-read User $subject
 * @property-read User|null $requester
 */
class EmployeeExport extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'subject_user_id', 'requested_by', 'status', 'disk', 'path', 'expires_at', 'completed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
