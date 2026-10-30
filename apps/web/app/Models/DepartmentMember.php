<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $department_id
 * @property int $user_id
 * @property bool $is_primary
 * @property CarbonInterface|null $created_at
 * @property-read Department $department
 */
class DepartmentMember extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'department_user';

    protected $fillable = ['workspace_id', 'department_id', 'user_id', 'is_primary'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
