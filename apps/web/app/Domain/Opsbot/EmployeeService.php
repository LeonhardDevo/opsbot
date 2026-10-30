<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Audit\Auditor;
use App\Billing\PlanLimitExceeded;
use App\Billing\PlanLimits;
use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Jobs\BuildEmployeeExport;
use App\Domain\Opsbot\Telegram\ContextInvalidator;
use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Locale\WithLocale;
use App\Models\AiQuery;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\EmployeeExport;
use App\Models\EmployeeLink;
use App\Models\TelegramAccount;
use App\Models\UnansweredQuestion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Outbox\Outbox;
use App\Outbox\Topics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EmployeeService
{
    public const string PHONE_PATTERN = '/^\+[1-9]\d{6,14}$/';

    public function __construct(
        private PlanLimits $limits,
        private DepartmentScope $scope,
        private Auditor $auditor,
        private ContextInvalidator $invalidator,
        private EmployeeLinkService $links,
        private TaskService $tasks,
        private Outbox $outbox,
    ) {}

    /**
     * @param  array{name: string, department_ids: list<int>, primary_department_id?: int|null, phone?: string|null, position?: string|null, locale: string}  $data
     *
     * @throws OpsbotException
     * @throws PlanLimitExceeded
     */
    public function create(Actor $actor, array $data): User
    {
        if (! $actor->can('employees.manage')) {
            throw OpsbotException::forbidden();
        }

        $departmentIds = $this->validDepartments($actor->workspace, $data['department_ids']);
        $this->assertDepartmentsInScope($actor, $departmentIds);
        $primary = $this->primaryFor($departmentIds, $data['primary_department_id'] ?? null);

        return DB::transaction(function () use ($actor, $data, $departmentIds, $primary): User {
            $this->limits->ensure($actor->workspace, 'seats');

            $user = new User;
            $user->forceFill([
                'name' => trim($data['name']),
                'email' => null,
                'password' => null,
                'locale' => $data['locale'],
                'current_workspace_id' => $actor->workspaceId(),
            ])->save();

            $membership = new WorkspaceMembership;
            $membership->forceFill([
                'workspace_id' => $actor->workspaceId(),
                'user_id' => $user->id,
                'role' => WorkspaceRole::Employee,
                'kind' => MemberKind::TelegramOnly,
                'status' => MemberStatus::Invited,
                'phone' => self::normalizePhone($data['phone'] ?? null),
                'position' => self::nullable($data['position'] ?? null),
            ])->save();

            $this->syncDepartments($actor->workspaceId(), $user->id, $departmentIds, $primary);

            $this->auditor->record(
                'employee.created',
                $user,
                metadata: ['department_ids' => $departmentIds, 'kind' => MemberKind::TelegramOnly->value],
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: $user->name,
            );

            return $user;
        });
    }

    /**
     * @param  array{name?: string, department_ids?: list<int>, primary_department_id?: int|null, phone?: string|null, position?: string|null, locale?: string, role?: string}  $data
     *
     * @throws OpsbotException
     */
    public function update(Actor $actor, User $employee, array $data): User
    {
        $membership = $employee->membershipIn($actor->workspace);

        if ($membership === null || ! $this->scope->canManageEmployee($actor, $employee)) {
            throw OpsbotException::forbidden();
        }

        $role = isset($data['role']) ? WorkspaceRole::from($data['role']) : null;

        if ($role !== null && $role !== $membership->role) {
            if (! $actor->can('members.manage') || ! in_array($role, $actor->role->assignableRoles(), true)) {
                throw OpsbotException::forbidden('employees.role_forbidden');
            }

            if ($role->requiresWebKind() && $membership->kind === MemberKind::TelegramOnly) {
                throw new OpsbotException('employees.role_requires_web');
            }
        }

        $departmentIds = null;
        $primary = null;

        if (array_key_exists('department_ids', $data)) {
            $departmentIds = $this->validDepartments($actor->workspace, $data['department_ids']);
            $this->assertDepartmentsInScope($actor, $departmentIds);
            $primary = $this->primaryFor($departmentIds, $data['primary_department_id'] ?? null);
        } elseif (array_key_exists('primary_department_id', $data)) {
            $departmentIds = $this->scope->departmentIds($employee, $actor->workspaceId());
            $primary = $this->primaryFor($departmentIds, $data['primary_department_id']);
        }

        return DB::transaction(function () use ($actor, $employee, $membership, $data, $role, $departmentIds, $primary): User {
            $before = $this->snapshot($actor->workspaceId(), $employee, $membership);

            if (isset($data['name']) && $employee->erased_at === null) {
                $employee->name = trim($data['name']);
            }

            if (isset($data['locale'])) {
                $employee->locale = $data['locale'];
            }

            $employee->save();

            if (array_key_exists('phone', $data)) {
                $membership->phone = self::normalizePhone($data['phone']);
            }

            if (array_key_exists('position', $data)) {
                $membership->position = self::nullable($data['position']);
            }

            if ($role !== null) {
                $membership->role = $role;
            }

            $membership->save();

            if ($departmentIds !== null) {
                $this->syncDepartments($actor->workspaceId(), $employee->id, $departmentIds, $primary);
                $this->scope->forget($employee, $actor->workspaceId());
            }

            $after = $this->snapshot($actor->workspaceId(), $employee, $membership->refresh());
            $changes = Auditor::diff($before, $after);

            if ($changes !== []) {
                $this->auditor->record(
                    'employee.updated',
                    $employee,
                    $changes,
                    actor: $actor->user,
                    workspace: $actor->workspace,
                    subjectLabel: $employee->displayName(),
                );
            }

            if (array_key_exists('role', $changes) || array_key_exists('department_ids', $changes)) {
                $this->invalidator->invalidate($actor->workspaceId(), $employee->id);
            }

            return $employee;
        });
    }

    /** @throws OpsbotException */
    public function offboard(Actor $actor, User $employee): void
    {
        $membership = $employee->membershipIn($actor->workspace);

        if ($membership === null || ! $this->scope->canManageEmployee($actor, $employee)) {
            throw OpsbotException::forbidden();
        }

        if ($membership->status === MemberStatus::Offboarded) {
            throw new OpsbotException('employees.already_offboarded');
        }

        if ($membership->role === WorkspaceRole::Owner && $actor->workspace->ownersCount() <= 1) {
            throw new OpsbotException('employees.last_owner');
        }

        DB::transaction(function () use ($actor, $employee, $membership): void {
            $keys = $this->invalidator->keysFor($actor->workspaceId(), $employee->id);
            $accounts = TelegramAccount::query()
                ->where('user_id', $employee->id)
                ->whereIn('bot_key', array_column($keys, 'bot_key'))
                ->get();

            foreach ($accounts as $account) {
                $text = WithLocale::run(
                    $employee->preferredLocale(),
                    fn (): string => (string) __('bot.access_revoked_html', ['workspace' => e($actor->workspace->name)]),
                );

                $this->outbox->publish(Topics::TELEGRAM_NOTIFY, [
                    'workspace_id' => $actor->workspaceId(),
                    'bot_key' => $account->bot_key,
                    'chat_id' => $account->chat_id,
                    'template' => 'opsbot.access_revoked',
                    'text_html' => $text,
                    'buttons' => [],
                    'reference' => ['type' => 'opsbot.access_revoked', 'id' => (string) Str::uuid()],
                ], $actor->workspaceId());
            }

            $previousStatus = $membership->status->value;

            $membership->forceFill([
                'status' => MemberStatus::Offboarded,
                'offboarded_at' => now(),
                'offboarded_by' => $actor->id(),
            ])->save();

            $reassigned = $this->tasks->reassignOpenTasks($actor->workspace, $employee, $actor->user);

            TelegramAccount::query()->whereIn('id', $accounts->pluck('id'))->delete();

            EmployeeLink::query()
                ->where('user_id', $employee->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            if ($employee->current_workspace_id === $actor->workspaceId()) {
                $employee->forceFill(['current_workspace_id' => null])->saveQuietly();
            }

            $this->invalidator->invalidate($actor->workspaceId(), $employee->id, $keys);

            $this->auditor->record(
                'employee.offboarded',
                $employee,
                ['status' => [$previousStatus, MemberStatus::Offboarded->value]],
                ['tasks_reassigned' => $reassigned],
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: $employee->displayName(),
            );
        });
    }

    /**
     * @return array{user_id: int, url: string, expires_at: string, bot_username: string}
     *
     * @throws OpsbotException
     * @throws PlanLimitExceeded
     */
    public function reactivate(Actor $actor, User $employee): array
    {
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $actor->workspaceId())
            ->where('user_id', $employee->id)
            ->first();

        if ($membership === null || ! $actor->can('employees.manage')) {
            throw OpsbotException::forbidden();
        }

        if (! $actor->privileged() && ! $actor->intersects($this->scope->departmentIds($employee, $actor->workspaceId()))) {
            throw OpsbotException::forbidden();
        }

        if ($membership->status !== MemberStatus::Offboarded) {
            throw new OpsbotException('employees.not_offboarded');
        }

        if ($employee->erased_at !== null) {
            throw new OpsbotException('employees.erased');
        }

        DB::transaction(function () use ($actor, $employee, $membership): void {
            $this->limits->ensure($actor->workspace, 'seats');

            $membership->forceFill([
                'status' => MemberStatus::Invited,
                'offboarded_at' => null,
                'offboarded_by' => null,
            ])->save();

            $this->auditor->record(
                'employee.reactivated',
                $employee,
                ['status' => [MemberStatus::Offboarded->value, MemberStatus::Invited->value]],
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: $employee->displayName(),
            );
        });

        return $this->links->issueFor($actor->workspace, $employee, $actor->user);
    }

    /** @throws OpsbotException */
    public function requestExport(Actor $actor, User $employee): EmployeeExport
    {
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $actor->workspaceId())
            ->where('user_id', $employee->id)
            ->first();

        $inScope = $actor->privileged() || $actor->intersects($this->scope->departmentIds($employee, $actor->workspaceId()));

        if ($membership === null || ! $actor->can('employees.manage') || ! $inScope || $actor->id() === $employee->id) {
            throw OpsbotException::forbidden();
        }

        $export = DB::transaction(function () use ($actor, $employee): EmployeeExport {
            $export = EmployeeExport::query()->create([
                'workspace_id' => $actor->workspaceId(),
                'subject_user_id' => $employee->id,
                'requested_by' => $actor->id(),
                'status' => 'queued',
                'expires_at' => now()->addHours((int) config('opsbot.export_ttl_hours', 24)),
            ]);

            $this->auditor->record(
                'employee.data_exported',
                $employee,
                metadata: ['export_id' => $export->id],
                actor: $actor->user,
                workspace: $actor->workspace,
                subjectLabel: $employee->displayName(),
            );

            return $export;
        });

        BuildEmployeeExport::dispatch($export->id)->afterCommit();

        return $export;
    }

    /** @throws OpsbotException */
    public function erase(Actor $actor, User $employee): void
    {
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $actor->workspaceId())
            ->where('user_id', $employee->id)
            ->first();

        if ($membership === null || ! $actor->privileged()) {
            throw OpsbotException::forbidden();
        }

        if ($membership->status !== MemberStatus::Offboarded) {
            throw new OpsbotException('employees.erase_requires_offboarded');
        }

        if ($employee->erased_at !== null) {
            throw new OpsbotException('employees.erased');
        }

        $otherWorkspaces = WorkspaceMembership::query()
            ->where('user_id', $employee->id)
            ->where('workspace_id', '!=', $actor->workspaceId())
            ->where('status', '!=', MemberStatus::Offboarded->value)
            ->exists();

        if ($otherWorkspaces) {
            throw new OpsbotException('employees.erase_other_workspaces');
        }

        DB::transaction(function () use ($actor, $employee, $membership): void {
            $keys = $this->invalidator->keysFor($actor->workspaceId(), $employee->id);

            $counts = [
                'telegram_accounts' => TelegramAccount::query()->where('user_id', $employee->id)->delete(),
                'employee_links' => EmployeeLink::query()->where('user_id', $employee->id)->delete(),
                'ai_queries' => AiQuery::query()->forWorkspace($actor->workspace)
                    ->where('user_id', $employee->id)
                    ->update(['question' => null, 'answer' => null]),
                'unanswered_questions' => UnansweredQuestion::query()->forWorkspace($actor->workspace)
                    ->where('employee_user_id', $employee->id)
                    ->update(['question' => '[erased]']),
            ];

            $membership->forceFill(['phone' => null, 'position' => null])->save();

            $employee->forceFill([
                'name' => 'Former employee #'.$employee->id,
                'email' => null,
                'password' => null,
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'erased_at' => now(),
            ])->save();

            $this->invalidator->invalidate($actor->workspaceId(), $employee->id, $keys);

            $this->auditor->record(
                'employee.erased',
                $employee,
                metadata: ['counts' => $counts],
                actor: $actor->user,
                workspace: $actor->workspace,
            );
        });
    }

    /** @param  list<int>  $departmentIds */
    public function syncDepartments(int $workspaceId, int $userId, array $departmentIds, ?int $primary): void
    {
        DepartmentMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->whereNotIn('department_id', $departmentIds === [] ? [0] : $departmentIds)
            ->delete();

        DepartmentMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->update(['is_primary' => false]);

        foreach ($departmentIds as $departmentId) {
            DepartmentMember::query()->updateOrCreate(
                ['department_id' => $departmentId, 'user_id' => $userId],
                ['workspace_id' => $workspaceId, 'is_primary' => $departmentId === $primary],
            );
        }
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $phone = self::nullable($phone);

        if ($phone === null) {
            return null;
        }

        $normalized = (string) preg_replace('/[\s()\-.]/', '', $phone);

        return preg_match(self::PHONE_PATTERN, $normalized) === 1 ? $normalized : throw new OpsbotException('employees.phone_invalid');
    }

    public static function maskPhone(?string $phone): ?string
    {
        if ($phone === null || strlen($phone) < 6) {
            return null;
        }

        return substr($phone, 0, 2).str_repeat('•', max(0, strlen($phone) - 6)).substr($phone, -4);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     *
     * @throws OpsbotException
     */
    private function validDepartments(Workspace $workspace, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if ($ids === []) {
            return [];
        }

        $found = Department::query()->forWorkspace($workspace)->live()->whereIn('id', $ids)->count();

        if ($found !== count($ids)) {
            throw new OpsbotException('departments.invalid');
        }

        return $ids;
    }

    /**
     * @param  list<int>  $departmentIds
     *
     * @throws OpsbotException
     */
    private function assertDepartmentsInScope(Actor $actor, array $departmentIds): void
    {
        if ($actor->privileged()) {
            return;
        }

        if ($departmentIds === [] || array_diff($departmentIds, $actor->departmentIds) !== []) {
            throw OpsbotException::forbidden('employees.department_scope');
        }
    }

    /**
     * @param  list<int>  $departmentIds
     *
     * @throws OpsbotException
     */
    private function primaryFor(array $departmentIds, ?int $primary): ?int
    {
        if ($departmentIds === []) {
            return null;
        }

        if ($primary === null) {
            return $departmentIds[0];
        }

        if (! in_array($primary, $departmentIds, true)) {
            throw new OpsbotException('employees.primary_department');
        }

        return $primary;
    }

    /** @return array<string, mixed> */
    private function snapshot(int $workspaceId, User $employee, WorkspaceMembership $membership): array
    {
        $this->scope->forget($employee, $workspaceId);

        return [
            'name' => $employee->name,
            'locale' => $employee->locale,
            'role' => $membership->role->value,
            'position' => $membership->position,
            'phone' => $membership->phone !== null ? self::maskPhone($membership->phone) : null,
            'department_ids' => $this->scope->departmentIds($employee, $workspaceId),
            'primary_department_id' => $this->scope->primaryDepartmentId($employee, $workspaceId),
        ];
    }

    private static function nullable(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
