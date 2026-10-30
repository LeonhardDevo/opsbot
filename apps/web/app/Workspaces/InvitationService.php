<?php

declare(strict_types=1);

namespace App\Workspaces;

use App\Audit\Auditor;
use App\Billing\PlanLimitExceeded;
use App\Billing\PlanLimits;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final readonly class InvitationService
{
    public function __construct(
        private Auditor $auditor,
        private PlanLimits $limits,
        private CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * @param  list<int>  $departmentIds
     * @return array{invitation: WorkspaceInvitation, token: string}
     *
     * @throws WorkspaceException
     * @throws PlanLimitExceeded
     */
    public function invite(Workspace $workspace, User $inviter, string $email, WorkspaceRole $role, array $departmentIds = []): array
    {
        $departmentIds = array_values(array_unique(array_map('intval', $departmentIds)));
        sort($departmentIds);

        if ($departmentIds !== [] && Department::query()->forWorkspace($workspace)->live()->whereIn('id', $departmentIds)->count() !== count($departmentIds)) {
            throw new WorkspaceException('invalid_departments');
        }

        $email = Str::lower(trim($email));
        $inviterRole = $inviter->roleIn($workspace);

        if ($inviterRole === null || ! $inviterRole->allows('members.manage')) {
            throw new WorkspaceException('insufficient_role');
        }

        if (! in_array($role, $inviterRole->assignableRoles(), true)) {
            throw new WorkspaceException('insufficient_role');
        }

        if ($workspace->members()->whereRaw('lower(users.email) = ?', [$email])->exists()) {
            throw new WorkspaceException('already_member', ['email' => $email]);
        }

        return DB::transaction(function () use ($workspace, $inviter, $email, $role, $departmentIds): array {
            $previous = WorkspaceInvitation::query()
                ->where('workspace_id', $workspace->id)
                ->whereRaw('lower(email) = ?', [$email])
                ->whereNull('accepted_at')
                ->get();
            $previous->each->delete();

            $this->limits->ensure($workspace, 'seats');

            $token = Str::random(64);
            $invitation = WorkspaceInvitation::query()->create([
                'workspace_id' => $workspace->id,
                'email' => $email,
                'role' => $role,
                'department_ids' => $departmentIds,
                'token_hash' => WorkspaceInvitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(WorkspaceInvitation::TTL_DAYS),
            ]);

            $mail = new WorkspaceInvitationMail($invitation, $token, $workspace, $inviter);
            Mail::to($email)->locale($workspace->locale)->send($mail);

            $this->auditor->record(
                'invitation.created',
                $invitation,
                metadata: ['email' => $email, 'role' => $role->value],
                actor: $inviter,
                workspace: $workspace,
                subjectLabel: $email,
            );

            return ['invitation' => $invitation, 'token' => $token];
        });
    }

    public function revoke(WorkspaceInvitation $invitation, User $actor): void
    {
        DB::transaction(function () use ($invitation, $actor): void {
            $this->auditor->record(
                'invitation.revoked',
                $invitation,
                metadata: ['email' => $invitation->email, 'role' => $invitation->role->value],
                actor: $actor,
                workspace: $invitation->workspace_id,
                subjectLabel: $invitation->email,
            );

            $invitation->delete();
        });
    }

    /** @throws WorkspaceException */
    public function accept(WorkspaceInvitation $invitation, User $user): Workspace
    {
        if (! $invitation->isPending()) {
            throw new WorkspaceException($invitation->accepted_at !== null ? 'invitation_used' : 'invitation_expired');
        }

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw new WorkspaceException('invitation_email_mismatch', ['email' => $invitation->email]);
        }

        $workspace = DB::transaction(function () use ($invitation, $user): Workspace {
            $locked = WorkspaceInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new WorkspaceException('invitation_used');
            }

            $workspace = $locked->workspace;

            if (! $user->belongsToWorkspace($workspace)) {
                $existing = WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->first();

                if ($existing !== null) {
                    $existing->forceFill(['role' => $locked->role, 'status' => MemberStatus::Active, 'offboarded_at' => null, 'offboarded_by' => null])->save();
                } else {
                    $workspace->addMember($user, $locked->role);
                }

                $hasPrimary = DepartmentMember::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->where('is_primary', true)->exists();

                foreach ($locked->department_ids as $index => $departmentId) {
                    DepartmentMember::query()->updateOrCreate(
                        ['department_id' => $departmentId, 'user_id' => $user->id],
                        ['workspace_id' => $workspace->id, 'is_primary' => $index === 0 && ! $hasPrimary],
                    );
                }
            }

            $locked->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $this->auditor->record(
                'invitation.accepted',
                $locked,
                metadata: ['email' => $locked->email, 'role' => $locked->role->value],
                actor: $user,
                workspace: $workspace,
                subjectLabel: $locked->email,
            );

            return $workspace;
        });

        $this->currentWorkspace->switchTo($user, $workspace);

        return $workspace;
    }
}
