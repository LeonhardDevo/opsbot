<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Support\DisplayNumber;
use App\Enums\BotStatus;
use App\Enums\IncidentStatus;
use App\Enums\QuestionStatus;
use App\Enums\Severity;
use App\Enums\TaskStatus;
use App\Models\Incident;
use App\Models\Task;
use App\Models\TelegramBot;
use App\Models\UnansweredQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class DeskFeed
{
    public function __construct(private DepartmentScope $scope) {}

    /** @return array{unanswered_open: int, incidents_open: int, tasks_overdue: int} */
    public function badges(Actor $actor): array
    {
        if (! $actor->can('incidents.manage') && ! $actor->can('questions.answer')) {
            return ['unanswered_open' => 0, 'incidents_open' => 0, 'tasks_overdue' => 0];
        }

        return [
            'unanswered_open' => $actor->can('questions.answer')
                ? $this->scope->questions(UnansweredQuestion::query()->forWorkspace($actor->workspace), $actor)->where('status', QuestionStatus::Open->value)->count()
                : 0,
            'incidents_open' => $this->scope->incidents(Incident::query()->withoutGlobalScopes(), $actor)
                ->whereIn('status', [IncidentStatus::Open->value, IncidentStatus::InProgress->value])
                ->count(),
            'tasks_overdue' => $this->scope->tasks(Task::query()->withoutGlobalScopes(), $actor)
                ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
                ->where('due_at', '<', now())
                ->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function today(Actor $actor, int $limit): array
    {
        $now = CarbonImmutable::now();
        $items = [];
        $manager = $actor->can('incidents.manage');

        $incidents = $this->scope->incidents(Incident::query()->withoutGlobalScopes()->with('reporter'), $actor)
            ->whereIn('status', [IncidentStatus::Open->value, IncidentStatus::InProgress->value])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        foreach ($incidents->filter(fn (Incident $incident): bool => $incident->severity === Severity::High) as $incident) {
            $items[] = [0, $this->incidentItem($actor, $incident)];
        }

        foreach ($incidents->filter(fn (Incident $incident): bool => $incident->severity !== Severity::High && $incident->assignee_id === null) as $incident) {
            $items[] = [1, $this->incidentItem($actor, $incident)];
        }

        if ($actor->can('questions.answer')) {
            $questions = $this->scope->questions(UnansweredQuestion::query()->forWorkspace($actor->workspace)->with('employee'), $actor)
                ->where('status', QuestionStatus::Open->value)
                ->where('created_at', '<', $now->subHours((int) config('opsbot.question_sla_hours', 24)))
                ->orderBy('created_at')
                ->limit(50)
                ->get();

            foreach ($questions as $question) {
                $items[] = [2, $this->questionItem($question)];
            }
        }

        $tasks = $this->scope->tasks(Task::query()->withoutGlobalScopes()->with('assignee'), $actor)
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->where('due_at', '<', $now)
            ->when(! $manager, fn ($query) => $query->where('assignee_id', $actor->id()))
            ->orderBy('due_at')
            ->limit(50)
            ->get();

        foreach ($tasks as $task) {
            $items[] = [3, $this->taskItem($actor, $task)];
        }

        if ($actor->can('bot.manage')) {
            $bot = TelegramBot::query()->where('workspace_id', $actor->workspaceId())->first();

            if ($bot !== null && in_array($bot->status, [BotStatus::Revoked, BotStatus::InvalidToken], true)) {
                $items[] = [6, $this->botItem($bot)];
            }
        }

        usort($items, fn (array $a, array $b): int => [$a[0], $b[1]['at']] <=> [$b[0], $a[1]['at']]);

        return array_slice(array_map(fn (array $item): array => $item[1], $items), 0, $limit);
    }

    /** @return array{items: list<array<string, mixed>>, cursor: string} */
    public function feed(Actor $actor, ?string $after, int $limit): array
    {
        $since = ($after !== null ? CarbonImmutable::parse($after) : CarbonImmutable::now()->subDays(7))->utc();
        $items = [];

        $incidents = $this->scope->incidents(Incident::query()->withoutGlobalScopes()->with('reporter'), $actor)
            ->where('updated_at', '>', $since)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        foreach ($incidents as $incident) {
            $items[] = $this->incidentItem($actor, $incident, $incident->updated_at->toIso8601String());
        }

        if ($actor->can('questions.answer')) {
            $questions = $this->scope->questions(UnansweredQuestion::query()->forWorkspace($actor->workspace)->with('employee'), $actor)
                ->where('status', QuestionStatus::Open->value)
                ->where('created_at', '>', $since)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            foreach ($questions as $question) {
                $items[] = $this->questionItem($question);
            }
        }

        $tasks = $this->scope->tasks(Task::query()->withoutGlobalScopes()->with('assignee'), $actor)
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->where('due_at', '<', now())
            ->where('due_at', '>', $since)
            ->when(! $actor->can('tasks.manage'), fn ($query) => $query->where('assignee_id', $actor->id()))
            ->orderByDesc('due_at')
            ->limit($limit)
            ->get();

        foreach ($tasks as $task) {
            $items[] = $this->taskItem($actor, $task);
        }

        if ($actor->can('bot.manage')) {
            $bot = TelegramBot::query()->where('workspace_id', $actor->workspaceId())->where('updated_at', '>', $since)->first();

            if ($bot !== null) {
                $items[] = $this->botItem($bot);
            }
        }

        usort($items, fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));
        $items = array_slice($items, 0, $limit);

        return [
            'items' => $items,
            'cursor' => $items !== [] ? (string) $items[0]['at'] : ($after ?? CarbonImmutable::now()->toIso8601String()),
        ];
    }

    /** @return array<string, mixed> */
    private function incidentItem(Actor $actor, Incident $incident, ?string $at = null): array
    {
        $actions = [];

        if ($incident->status === IncidentStatus::Open && ($this->scope->canManageIncident($actor, $incident) || $incident->assignee_id === $actor->id())) {
            $actions[] = 'take';
        }

        $actions[] = 'open';

        return [
            'id' => 'incident:'.$incident->id,
            'kind' => 'incident',
            'title' => DisplayNumber::incident($incident->number).' '.$incident->title,
            'excerpt' => Str::limit($incident->description, 160),
            'actor' => $incident->reporter?->toRef(),
            'at' => $at ?? $incident->created_at->toIso8601String(),
            'url' => '/incidents/'.$incident->id,
            'severity' => $incident->severity->value,
            'actions' => $actions,
        ];
    }

    /** @return array<string, mixed> */
    private function questionItem(UnansweredQuestion $question): array
    {
        return [
            'id' => 'question:'.$question->id,
            'kind' => 'question',
            'title' => DisplayNumber::question($question->number),
            'excerpt' => Str::limit($question->question, 160),
            'actor' => $question->employee->toRef(),
            'at' => $question->created_at->toIso8601String(),
            'url' => '/unanswered?focus='.$question->id,
            'actions' => ['answer', 'open'],
        ];
    }

    /** @return array<string, mixed> */
    private function taskItem(Actor $actor, Task $task): array
    {
        $actions = [];

        if ($task->assignee_id === $actor->id() || $task->created_by === $actor->id() || $this->scope->canManageTask($actor, $task)) {
            $actions[] = 'done';
        }

        $actions[] = 'open';

        return [
            'id' => 'task_overdue:'.$task->id,
            'kind' => 'task_overdue',
            'title' => DisplayNumber::task($task->number).' '.$task->title,
            'excerpt' => $task->description !== null ? Str::limit($task->description, 160) : null,
            'actor' => $task->assignee?->toRef(),
            'at' => ($task->due_at ?? $task->created_at)->toIso8601String(),
            'url' => '/tasks?focus='.$task->id,
            'actions' => $actions,
        ];
    }

    /** @return array<string, mixed> */
    private function botItem(TelegramBot $bot): array
    {
        return [
            'id' => 'bot:'.$bot->id,
            'kind' => 'bot',
            'title' => (string) __('bot.desk.'.$bot->status->value, ['username' => $bot->username ?? (string) $bot->tg_bot_id]),
            'excerpt' => $bot->last_error !== null ? $bot->last_error->label() : null,
            'actor' => null,
            'at' => ($bot->updated_at ?? CarbonImmutable::now())->toIso8601String(),
            'url' => '/bot',
            'actions' => ['open'],
        ];
    }
}
