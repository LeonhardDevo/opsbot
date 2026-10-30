<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property WorkspaceRole $role
 * @property MemberKind $kind
 * @property MemberStatus $status
 * @property string|null $phone
 * @property string|null $position
 * @property CarbonInterface|null $offboarded_at
 * @property int|null $offboarded_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class WorkspaceMembership extends Pivot
{
    protected $table = 'workspace_user';

    public $incrementing = true;

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => 'web',
        'status' => 'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'kind' => MemberKind::class,
            'status' => MemberStatus::class,
            'phone' => 'encrypted',
            'offboarded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === MemberStatus::Active;
    }
}
