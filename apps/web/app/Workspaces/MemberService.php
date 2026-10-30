<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Audit\Auditor;
use App\Domain\Opsbot\Telegram\ContextInvalidator;
use App\Enums\MemberKind;
use App\Enums\WorkspaceRole;
use App\Models\DepartmentMember;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class MemberService
{
    public function __construct(
        private Auditor $auditor,
        private ContextInvalidator $invalidator,
    ) {}

    /** @throws WorkspaceException */
    public function changeRole(Workspace $workspace, User $actor, User $member, WorkspaceRole $newRole): void
    {
        $actorRole = $actor->roleIn($workspace);
        $currentRole = $member->roleIn($workspace);

        if ($actorRole === null || $currentRole === null) {
            throw new WorkspaceException('not_a_member');
        }

        if ($actor->is($member)) {
            throw new WorkspaceException('cannot_change_own_role');
        }

        if (! $actorRole->canManage($currentRole) || ! in_array($newRole, $actorRole->assignableRoles(), true)) {
            throw new WorkspaceException('insufficient_role');
        }

        if ($currentRole === $newRole) {
            return;
        }

        DB::transaction(function () use ($workspace, $actor, $member, $currentRole, $newRole): void {
            if ($currentRole === WorkspaceRole::Owner && $workspace->ownersCount() <= 1) {
                throw new WorkspaceException('last_owner');
            }

            if ($newRole->requiresWebKind() && $member->membershipIn($workspace)?->kind === MemberKind::TelegramOnly) {
                throw new WorkspaceException('insufficient_role');
            }

            $workspace->members()->updateExistingPivot($member->id, ['role' => $newRole->value]);
            $this->invalidator->invalidate($workspace->id, $member->id);

            $this->auditor->record(
                'member.role_changed',
                $member,
                ['role' => [$currentRole->value, $newRole->value]],
                actor: $actor,
                workspace: $workspace,
                subjectLabel: $member->name,
            );
        });
    }

    /** @throws WorkspaceException */
    public function remove(Workspace $workspace, User $actor, User $member): void
    {
        $actorRole = $actor->roleIn($workspace);
        $memberRole = $member->roleIn($workspace);

        if ($actorRole === null || $memberRole === null) {
            throw new WorkspaceException('not_a_member');
        }

        $leaving = $actor->is($member);

        if (! $leaving && (! $actorRole->allows('members.manage') || ! $actorRole->canManage($memberRole))) {
            throw new WorkspaceException('insufficient_role');
        }

        DB::transaction(function () use ($workspace, $actor, $member, $memberRole, $leaving): void {
            if ($memberRole === WorkspaceRole::Owner && $workspace->ownersCount() <= 1) {
                throw new WorkspaceException('last_owner');
            }

            $keys = $this->invalidator->keysFor($workspace->id, $member->id);
            $workspace->allMembers()->detach($member->id);
            DepartmentMember::query()->where('workspace_id', $workspace->id)->where('user_id', $member->id)->delete();
            $this->invalidator->invalidate($workspace->id, $member->id, $keys);

            if ($member->current_workspace_id === $workspace->id) {
                $member->forceFill(['current_workspace_id' => null])->saveQuietly();
            }

            TelegramAccount::query()
                ->where('user_id', $member->id)
                ->where('workspace_id', $workspace->id)
                ->update(['workspace_id' => null]);

            $this->auditor->record(
                $leaving ? 'member.left' : 'member.removed',
                $member,
                ['role' => [$memberRole->value, null]],
                actor: $actor,
                workspace: $workspace,
                subjectLabel: $member->name,
            );
        });
    }
}
