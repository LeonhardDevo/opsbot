<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Access\ActorFactory;
use App\Domain\Opsbot\OpsbotException;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

abstract class OpsController extends Controller
{
    protected function actor(): Actor
    {
        return app(ActorFactory::class)->current();
    }

    /** @throws OpsbotException */
    protected function require(Actor $actor, string $ability): void
    {
        if (! $actor->can($ability)) {
            throw OpsbotException::forbidden();
        }
    }

    /** @return list<array{id: int, name: string, code: string}> */
    protected function departmentRefs(Workspace $workspace): array
    {
        return array_values(Department::query()
            ->forWorkspace($workspace)
            ->live()
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => $department->toRef())
            ->all());
    }

    /** @return list<array{id: int, name: string}> */
    protected function assignableUsers(Workspace $workspace, string $ability): array
    {
        return array_values(User::query()
            ->join('workspace_user', 'workspace_user.user_id', '=', 'users.id')
            ->where('workspace_user.workspace_id', $workspace->id)
            ->where('workspace_user.status', 'active')
            ->whereIn('workspace_user.role', $this->rolesWith($ability))
            ->whereNull('users.erased_at')
            ->orderBy('users.name')
            ->get(['users.*'])
            ->map(fn (User $user): array => $user->toRef())
            ->all());
    }

    /** @throws OpsbotException */
    protected function workspaceUser(Workspace $workspace, User $user): WorkspaceMembership
    {
        $membership = $user->membershipIn($workspace);

        if ($membership === null) {
            throw OpsbotException::notFound();
        }

        return $membership;
    }

    /** @return list<string> */
    private function rolesWith(string $ability): array
    {
        $roles = [];

        foreach (WorkspaceRole::cases() as $role) {
            if ($role->allows($ability)) {
                $roles[] = $role->value;
            }
        }

        return $roles;
    }
}
