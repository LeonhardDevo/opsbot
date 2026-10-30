<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Access;

use App\Domain\Opsbot\DepartmentScope;
use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ActorFactory
{
    public function __construct(
        private CurrentWorkspace $current,
        private DepartmentScope $scope,
    ) {}

    public function current(): Actor
    {
        $workspace = $this->current->get();
        $role = $this->current->role();
        $user = auth()->user();

        if (! $user instanceof User || $workspace === null || $role === null) {
            throw new AuthorizationException;
        }

        $membership = $user->membershipIn($workspace);

        return new Actor(
            $user,
            $workspace,
            $role,
            $this->scope->departmentIds($user, $workspace->id),
            $membership->kind ?? MemberKind::Web,
        );
    }

    public function forUser(User $user, Workspace $workspace): ?Actor
    {
        $membership = $user->membershipIn($workspace);

        if ($membership === null || $membership->status === MemberStatus::Offboarded || $user->erased_at !== null) {
            return null;
        }

        return new Actor($user, $workspace, $membership->role, $this->scope->departmentIds($user, $workspace->id), $membership->kind);
    }
}
