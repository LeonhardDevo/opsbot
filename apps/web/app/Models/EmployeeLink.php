<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $bot_key
 * @property string $token_hash
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $used_at
 * @property CarbonInterface|null $revoked_at
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property-read User $user
 * @property-read Workspace $workspace
 */
class EmployeeLink extends Model
{
    public const UPDATED_AT = null;

    public const string PREFIX = 'emp_';

    protected $fillable = ['workspace_id', 'user_id', 'bot_key', 'token_hash', 'expires_at', 'created_by'];

    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
