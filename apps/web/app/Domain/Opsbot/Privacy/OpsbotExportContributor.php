<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Privacy;

use App\Models\User;
use App\Models\Workspace;
use App\Privacy\ExportContributor;
use Illuminate\Support\Facades\DB;

final class OpsbotExportContributor implements ExportContributor
{
    private const array INCIDENT_COLUMNS = [
        'workspace_id', 'number', 'category', 'severity', 'status', 'title', 'description', 'client_ref',
        'department_id', 'reporter_id', 'assignee_id', 'source', 'resolved_at', 'closed_at', 'created_at',
    ];

    private const array TASK_COLUMNS = [
        'workspace_id', 'number', 'title', 'description', 'status', 'due_at', 'assignee_id', 'created_by',
        'department_id', 'incident_id', 'source', 'completed_at', 'cancelled_at', 'created_at',
    ];

    private const array QUESTION_COLUMNS = [
        'workspace_id', 'number', 'question', 'employee_user_id', 'department_id', 'assignee_user_id', 'reason',
        'status', 'answer_text', 'answered_at', 'dismissed_reason', 'dismissed_at', 'created_at',
    ];

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        return [
            'workspace_memberships' => DB::table('workspace_user')
                ->where('user_id', $user->id)
                ->orderBy('workspace_id')
                ->get(['workspace_id', 'role', 'kind', 'status', 'phone', 'position', 'offboarded_at', 'created_at'])
                ->all(),
            'departments' => DB::table('department_user')
                ->join('departments', 'departments.id', '=', 'department_user.department_id')
                ->where('department_user.user_id', $user->id)
                ->orderBy('departments.id')
                ->get(['departments.workspace_id', 'departments.name', 'department_user.is_primary'])
                ->all(),
            'incidents_reported' => DB::table('incidents')
                ->where('reporter_id', $user->id)
                ->orderBy('id')
                ->get(self::INCIDENT_COLUMNS)
                ->all(),
            'incidents_assigned' => DB::table('incidents')
                ->where('assignee_id', $user->id)
                ->orderBy('id')
                ->get(self::INCIDENT_COLUMNS)
                ->all(),
            'tasks' => DB::table('tasks')
                ->where(fn ($query) => $query->where('assignee_id', $user->id)->orWhere('created_by', $user->id))
                ->orderBy('id')
                ->get(self::TASK_COLUMNS)
                ->all(),
            'questions_to_managers' => DB::table('unanswered_questions')
                ->where('employee_user_id', $user->id)
                ->orderBy('id')
                ->get(self::QUESTION_COLUMNS)
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function forWorkspace(Workspace $workspace): array
    {
        return [
            'market' => ['country' => $workspace->country, 'currency' => $workspace->currency],
            'employees' => DB::table('workspace_user')
                ->join('users', 'users.id', '=', 'workspace_user.user_id')
                ->where('workspace_user.workspace_id', $workspace->id)
                ->orderBy('users.id')
                ->get([
                    'users.id', 'users.name', 'users.email', 'workspace_user.role', 'workspace_user.kind',
                    'workspace_user.status', 'workspace_user.phone', 'workspace_user.position', 'workspace_user.offboarded_at',
                ])
                ->all(),
            'departments' => DB::table('departments')
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->get(['id', 'name', 'code', 'manager_user_id', 'archived_at', 'created_at'])
                ->all(),
            'department_members' => DB::table('department_user')
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->get(['department_id', 'user_id', 'is_primary'])
                ->all(),
            'routing_rules' => DB::table('routing_rules')
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->get(['department_id', 'category', 'manager_user_id'])
                ->all(),
            'incidents' => DB::table('incidents')
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->get(self::INCIDENT_COLUMNS)
                ->all(),
            'incident_events' => DB::table('incident_events')
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->get(['incident_id', 'actor_id', 'type', 'from_value', 'to_value', 'body', 'created_at'])
                ->all(),
            'tasks' => DB::table('tasks')
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->get(self::TASK_COLUMNS)
                ->all(),
            'questions_to_managers' => DB::table('unanswered_questions')
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->get(self::QUESTION_COLUMNS)
                ->all(),
            'telegram_bot' => DB::table('telegram_bots')
                ->where('workspace_id', $workspace->id)
                ->first(['username', 'status', 'mode', 'connected_at', 'disconnected_at']),
            'assistant_settings' => DB::table('ops_settings')
                ->where('workspace_id', $workspace->id)
                ->first(['min_confidence', 'quiet_hours_start', 'quiet_hours_end', 'reminders_enabled']),
        ];
    }
}
