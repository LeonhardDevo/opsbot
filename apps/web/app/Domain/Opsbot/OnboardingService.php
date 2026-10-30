<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Audit\Auditor;
use App\Enums\BotStatus;
use App\Enums\MemberKind;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\TelegramBot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

final readonly class OnboardingService
{
    public const array STEPS = ['workspace', 'bot', 'knowledge', 'team'];

    public function __construct(private Auditor $auditor) {}

    /** @return list<array{key: string, state: string}> */
    public function steps(Workspace $workspace): array
    {
        $saved = $workspace->onboarding;
        $done = [
            'workspace' => true,
            'bot' => ($saved['bot'] ?? null) === 'done' || TelegramBot::query()->where('workspace_id', $workspace->id)->where('status', BotStatus::Active->value)->exists(),
            'knowledge' => ($saved['knowledge'] ?? null) === 'done' || Document::query()->forWorkspace($workspace)->exists(),
            'team' => ($saved['team'] ?? null) === 'done' || WorkspaceMembership::query()
                ->where('workspace_id', $workspace->id)
                ->where('kind', MemberKind::TelegramOnly->value)
                ->exists(),
        ];

        $steps = [];
        $currentAssigned = false;

        foreach (self::STEPS as $key) {
            if ($done[$key]) {
                $state = 'done';
            } elseif (($saved[$key] ?? null) === 'skipped') {
                $state = 'skipped';
            } elseif (! $currentAssigned) {
                $state = 'current';
                $currentAssigned = true;
            } else {
                $state = 'todo';
            }

            $steps[] = ['key' => $key, 'state' => $state];
        }

        return $steps;
    }

    public function pending(Workspace $workspace, ?WorkspaceRole $role): bool
    {
        return $role !== null && $role->isPrivileged() && ($workspace->onboarding['finished_at'] ?? null) === null;
    }

    public function skip(Workspace $workspace, User $actor, string $step): void
    {
        $onboarding = $workspace->onboarding;

        if (($onboarding[$step] ?? null) !== 'done') {
            $onboarding[$step] = 'skipped';
        }

        $workspace->onboarding = $onboarding;
        $workspace->save();

        $this->auditor->record('workspace.onboarding_step_skipped', $workspace, metadata: ['step' => $step], actor: $actor, workspace: $workspace, subjectLabel: $workspace->name);
    }

    public function finish(Workspace $workspace, User $actor): void
    {
        $onboarding = $workspace->onboarding;

        foreach ($this->steps($workspace) as $step) {
            if ($step['state'] === 'done' && $step['key'] !== 'workspace') {
                $onboarding[$step['key']] = 'done';
            }
        }

        $onboarding['finished_at'] = now()->toIso8601String();
        $workspace->onboarding = $onboarding;
        $workspace->save();

        $this->auditor->record('workspace.onboarding_finished', $workspace, actor: $actor, workspace: $workspace, subjectLabel: $workspace->name);
    }
}
