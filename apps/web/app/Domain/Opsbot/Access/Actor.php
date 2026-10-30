<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Access;

use App\Enums\MemberKind;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

final readonly class Actor
{
    /** @param  list<int>  $departmentIds */
    public function __construct(
        public User $user,
        public Workspace $workspace,
        public WorkspaceRole $role,
        public array $departmentIds,
        public MemberKind $kind = MemberKind::Web,
    ) {}

    public function can(string $ability): bool
    {
        return $this->role->allows($ability);
    }

    public function id(): int
    {
        return $this->user->id;
    }

    public function workspaceId(): int
    {
        return $this->workspace->id;
    }

    public function privileged(): bool
    {
        return $this->role->isPrivileged();
    }

    public function inDepartment(?int $departmentId): bool
    {
        return $departmentId !== null && in_array($departmentId, $this->departmentIds, true);
    }

    /** @param  list<int>  $departmentIds */
    public function intersects(array $departmentIds): bool
    {
        return array_intersect($departmentIds, $this->departmentIds) !== [];
    }

    public function locale(): string
    {
        return $this->user->preferredLocale();
    }
}
