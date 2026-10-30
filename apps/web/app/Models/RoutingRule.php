<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoutingCategory;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $department_id
 * @property RoutingCategory $category
 * @property int $manager_user_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $manager
 */
class RoutingRule extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'department_id', 'category', 'manager_user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => RoutingCategory::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScopes();
    }
}
