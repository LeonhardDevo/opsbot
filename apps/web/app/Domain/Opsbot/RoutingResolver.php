<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Enums\MemberStatus;
use App\Enums\RoutingCategory;
use App\Enums\WorkspaceRole;
use App\Models\Department;
use App\Models\RoutingRule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

final class RoutingResolver
{
    public const string SOURCE_RULE = 'rule';

    public const string SOURCE_DEPARTMENT_MANAGER = 'department_manager';

    public const string SOURCE_WORKSPACE_OWNER = 'workspace_owner';

    /** @return array{user: User|null, department: Department|null, source: string} */
    public function resolve(Workspace $workspace, RoutingCategory $category, ?int $departmentId): array
    {
        $department = $departmentId !== null
            ? Department::query()->forWorkspace($workspace)->whereKey($departmentId)->first()
            : null;

        $rules = RoutingRule::query()
            ->forWorkspace($workspace)
            ->where('category', $category->value)
            ->where(function ($query) use ($department): void {
                $query->whereNull('department_id');

                if ($department !== null) {
                    $query->orWhere('department_id', $department->id);
                }
            })
            ->get()
            ->sortBy(fn (RoutingRule $rule): int => $rule->department_id === null ? 1 : 0)
            ->values();

        foreach ($rules as $rule) {
            $manager = $this->activeManager($workspace, $rule->manager_user_id);

            if ($manager !== null) {
                return ['user' => $manager, 'department' => $department, 'source' => self::SOURCE_RULE];
            }
        }

        if ($department !== null && $department->manager_user_id !== null) {
            $manager = $this->activeManager($workspace, $department->manager_user_id);

            if ($manager !== null) {
                return ['user' => $manager, 'department' => $department, 'source' => self::SOURCE_DEPARTMENT_MANAGER];
            }
        }

        return ['user' => $workspace->oldestActiveOwner(), 'department' => $department, 'source' => self::SOURCE_WORKSPACE_OWNER];
    }

    public function activeManager(Workspace $workspace, ?int $userId): ?User
    {
        if ($userId === null) {
            return null;
        }

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->first();

        if ($membership === null || $membership->status !== MemberStatus::Active || ! $membership->role->atLeast(WorkspaceRole::Manager)) {
            return null;
        }

        $user = User::query()->find($userId);

        return $user !== null && $user->erased_at === null ? $user : null;
    }
}
