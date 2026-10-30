<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Access\ActorFactory;
use App\Domain\Opsbot\DepartmentScope;
use App\Enums\AiChannel;
use App\Enums\BotStatus;
use App\Enums\IncidentCategory;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Enums\QuestionReason;
use App\Enums\QuestionStatus;
use App\Enums\Severity;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiQuery;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\Incident;
use App\Models\Task;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Models\UnansweredQuestion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Str;

trait OpsbotFixtures
{
    public function department(Workspace $workspace, string $code = 'residential', ?User $manager = null): Department
    {
        return Department::query()->create([
            'workspace_id' => $workspace->id,
            'name' => Str::headline($code),
            'code' => $code,
            'manager_user_id' => $manager?->id,
        ]);
    }

    public function inDepartment(User $user, Department $department, bool $primary = true): User
    {
        DepartmentMember::query()->create([
            'workspace_id' => $department->workspace_id,
            'department_id' => $department->id,
            'user_id' => $user->id,
            'is_primary' => $primary,
        ]);
        app(DepartmentScope::class)->forget();

        return $user;
    }

    public function telegramEmployee(Workspace $workspace, string $name = 'Айгуль', MemberStatus $status = MemberStatus::Active, string $locale = 'ru'): User
    {
        $user = new User;
        $user->forceFill(['name' => $name, 'email' => null, 'password' => null, 'locale' => $locale])->save();

        $membership = new WorkspaceMembership;
        $membership->forceFill([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceRole::Employee,
            'kind' => MemberKind::TelegramOnly,
            'status' => $status,
        ])->save();

        return $user;
    }

    public function companyBot(Workspace $workspace, BotStatus $status = BotStatus::Active, int $tgBotId = 7000000001): TelegramBot
    {
        return TelegramBot::query()->create([
            'workspace_id' => $workspace->id,
            'tg_bot_id' => $tgBotId,
            'username' => 'test_'.$tgBotId.'_bot',
            'first_name' => 'Test Bot',
            'token' => $tgBotId.':TEST'.str_repeat('a', 30),
            'webhook_secret' => str_repeat('s', 32),
            'status' => $status,
            'mode' => 'fake',
            'connected_at' => now(),
        ]);
    }

    public function linkTelegram(User $user, Workspace $workspace, string $botKey, int $telegramUserId): TelegramAccount
    {
        return TelegramAccount::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'bot_key' => $botKey,
            'telegram_bot_id' => $botKey === 'platform' ? null : (int) $botKey,
            'telegram_user_id' => $telegramUserId,
            'chat_id' => $telegramUserId,
            'linked_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    public function incident(Workspace $workspace, array $attributes = []): Incident
    {
        $number = (int) Incident::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->max('number') + 1;
        $incident = new Incident;
        $incident->forceFill([
            'workspace_id' => $workspace->id,
            'number' => $number,
            'category' => IncidentCategory::Damage,
            'severity' => Severity::Medium,
            'status' => IncidentStatus::Open,
            'title' => 'Damage — test',
            'description' => 'Scratched a table',
            'source' => IncidentSource::Web,
            ...$attributes,
        ])->save();

        return $incident;
    }

    /** @param  array<string, mixed>  $attributes */
    public function task(Workspace $workspace, array $attributes = []): Task
    {
        $number = (int) Task::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->max('number') + 1;
        $task = new Task;
        $task->forceFill([
            'workspace_id' => $workspace->id,
            'number' => $number,
            'title' => 'Return keys',
            'status' => TaskStatus::Todo,
            'source' => TaskSource::Web,
            ...$attributes,
        ])->save();

        return $task;
    }

    /** @param  array<string, mixed>  $attributes */
    public function aiQuery(Workspace $workspace, ?User $user, array $attributes = []): AiQuery
    {
        $query = new AiQuery;
        $query->forceFill([
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'channel' => AiChannel::Telegram,
            'question' => 'What is the guest Wi-Fi password?',
            'answer' => null,
            'refused' => true,
            'confidence' => 0.1,
            'citations' => [],
            'tools_used' => [],
            ...$attributes,
        ])->save();

        return $query;
    }

    /** @param  array<string, mixed>  $attributes */
    public function question(Workspace $workspace, User $employee, array $attributes = []): UnansweredQuestion
    {
        $number = (int) UnansweredQuestion::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->max('number') + 1;
        $question = new UnansweredQuestion;
        $question->forceFill([
            'workspace_id' => $workspace->id,
            'number' => $number,
            'question' => 'Guest Wi-Fi password?',
            'employee_user_id' => $employee->id,
            'reason' => QuestionReason::NotFound,
            'status' => QuestionStatus::Open,
            ...$attributes,
        ])->save();

        return $question;
    }

    public function actorFor(User $user, Workspace $workspace): Actor
    {
        app(DepartmentScope::class)->forget();
        $actor = app(ActorFactory::class)->forUser($user, $workspace);

        if ($actor === null) {
            throw new \LogicException('User is not an active member.');
        }

        return $actor;
    }
}
