<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $code
 * @property int|null $manager_user_id
 * @property CarbonInterface|null $archived_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $manager
 */
class Department extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'name', 'code', 'manager_user_id', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user')->withPivot(['is_primary', 'workspace_id']);
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @return array{id: int, name: string, code: string} */
    public function toRef(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'code' => $this->code];
    }
}
