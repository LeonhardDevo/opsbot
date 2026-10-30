<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Presenters;

use App\Domain\Opsbot\EmployeeService;
use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Enums\TaskStatus;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\EmployeeLink;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EmployeePresenter
{
    /**
     * @param  list<int>  $userIds
     * @return array<string, mixed>
     */
    public static function context(int $workspaceId, array $userIds, ?string $activeBotKey): array
    {
        $ids = $userIds === [] ? [0] : $userIds;

        return [
            'memberships' => WorkspaceMembership::query()->where('workspace_id', $workspaceId)->whereIn('user_id', $ids)->get()->keyBy('user_id'),
            'departments' => DepartmentMember::query()->where('workspace_id', $workspaceId)->whereIn('user_id', $ids)->orderBy('department_id')->get()->groupBy('user_id'),
            'departmentRefs' => Department::query()->forWorkspace($workspaceId)->get()->keyBy('id'),
            'accounts' => TelegramAccount::query()->whereIn('user_id', $ids)->where(function ($query) use ($workspaceId, $activeBotKey): void {
                $query->where('workspace_id', $workspaceId);

                if ($activeBotKey !== null) {
                    $query->orWhere('bot_key', $activeBotKey);
                }
            })->get()->groupBy('user_id'),
            'links' => EmployeeLink::query()->where('workspace_id', $workspaceId)->whereIn('user_id', $ids)->active()->get()->keyBy('user_id'),
            'lastQuestions' => collect(DB::select(
                'SELECT user_id, max(created_at) AS last_at FROM ai_queries WHERE workspace_id = ? AND user_id = ANY(?::bigint[]) GROUP BY user_id',
                [$workspaceId, '{'.implode(',', $ids).'}'],
            ))->keyBy('user_id'),
            'openTasks' => collect(DB::select(
                'SELECT assignee_id, count(*) AS total FROM tasks WHERE workspace_id = ? AND assignee_id = ANY(?::bigint[]) AND status IN (?, ?) GROUP BY assignee_id',
                [$workspaceId, '{'.implode(',', $ids).'}', TaskStatus::Todo->value, TaskStatus::InProgress->value],
            ))->keyBy('assignee_id'),
            'activeBotKey' => $activeBotKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function present(User $user, array $context, ?int $viewerId): array
    {
        /** @var WorkspaceMembership|null $membership */
        $membership = $context['memberships']->get($user->id);
        /** @var Collection<int, DepartmentMember> $departmentRows */
        $departmentRows = $context['departments']->get($user->id) ?? collect();
        /** @var Collection<int, TelegramAccount> $accounts */
        $accounts = $context['accounts']->get($user->id) ?? collect();
        $activeBotKey = $context['activeBotKey'];
        $account = $activeBotKey !== null
            ? $accounts->firstWhere('bot_key', $activeBotKey)
            : $accounts->first();
        /** @var EmployeeLink|null $link */
        $link = $context['links']->get($user->id);
        $lastQuestion = $context['lastQuestions']->get($user->id);
        $openTasks = $context['openTasks']->get($user->id);

        $departments = [];
        $primary = null;

        foreach ($departmentRows as $row) {
            $department = $context['departmentRefs']->get($row->department_id);

            if ($department !== null) {
                $departments[] = $department->toRef();
            }

            if ($row->is_primary) {
                $primary = $row->department_id;
            }
        }

        $kind = $membership->kind ?? MemberKind::Web;
        $status = $membership->status ?? MemberStatus::Active;

        return [
            'id' => $user->id,
            'name' => $user->displayName(),
            'email' => $user->email,
            'phone_masked' => EmployeeService::maskPhone($membership?->phone),
            'position' => $membership?->position,
            'role' => $membership?->role->value,
            'kind' => $kind->value,
            'status' => $status->value,
            'departments' => $departments,
            'primary_department_id' => $primary,
            'telegram' => [
                'state' => $account === null ? 'not_linked' : ($account->blocked_at !== null ? 'blocked' : 'linked'),
                'username' => $account?->username,
                'linked_at' => $account?->linked_at->toIso8601String(),
                'bot_key' => $account?->bot_key,
            ],
            'link' => [
                'active' => $link !== null,
                'expires_at' => $link?->expires_at->toIso8601String(),
            ],
            'needs_relink' => $kind === MemberKind::TelegramOnly && $status === MemberStatus::Active && $account === null,
            'last_question_at' => $lastQuestion !== null && $lastQuestion->last_at !== null ? CarbonImmutable::parse((string) $lastQuestion->last_at)->utc()->toIso8601String() : null,
            'open_tasks' => (int) ($openTasks->total ?? 0),
            'created_at' => $membership?->created_at?->toIso8601String() ?? $user->created_at?->toIso8601String(),
            'is_you' => $viewerId === $user->id,
        ];
    }
}
