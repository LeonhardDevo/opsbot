<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Telegram;

use App\Audit\Auditor;
use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Actions\ActionExecutor;
use App\Domain\Opsbot\Actions\ActionOutcome;
use App\Domain\Opsbot\EscalationService;
use App\Domain\Opsbot\IncidentService;
use App\Domain\Opsbot\OpsbotException;
use App\Domain\Opsbot\Support\DisplayNumber;
use App\Domain\Opsbot\TaskService;
use App\Enums\AiChannel;
use App\Enums\DismissReason;
use App\Enums\IncidentStatus;
use App\Enums\TaskStatus;
use App\Models\Incident;
use App\Models\Task;
use App\Models\UnansweredQuestion;

final readonly class TelegramActionHandler
{
    public const array TYPES = ['opsbot.action', 'incident_take', 'incident_resolve', 'task_start', 'task_done', 'q_dismiss', 'q_convert'];

    public function __construct(
        private ActionExecutor $executor,
        private IncidentService $incidents,
        private TaskService $tasks,
        private EscalationService $escalations,
    ) {}

    public static function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** @param  array<string, mixed>  $payload */
    public function handle(Actor $actor, string $type, array $payload): ActionOutcome
    {
        $args = self::args($payload);

        try {
            return match ($type) {
                'opsbot.action' => $this->executor->handle(
                    $actor,
                    (string) ($payload['action_id'] ?? $args[0] ?? ''),
                    (string) ($payload['step'] ?? $args[1] ?? ActionExecutor::STEP_PREVIEW),
                    AiChannel::Telegram,
                ),
                'incident_take' => $this->incident($actor, self::id($payload, $args), IncidentStatus::InProgress),
                'incident_resolve' => $this->incident($actor, self::id($payload, $args), IncidentStatus::Resolved),
                'task_start' => $this->task($actor, self::id($payload, $args), TaskStatus::InProgress),
                'task_done' => $this->task($actor, self::id($payload, $args), TaskStatus::Done),
                'q_dismiss' => $this->dismiss($actor, self::id($payload, $args), (string) ($payload['reason'] ?? $args[1] ?? '')),
                'q_convert' => $this->convert($actor, self::id($payload, $args)),
                default => ActionOutcome::failure((string) __('opsbot.actions_result.unavailable')),
            };
        } catch (OpsbotException $exception) {
            return ActionOutcome::failure($exception->userMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function args(array $payload): array
    {
        $arg = $payload['arg'] ?? null;

        return is_string($arg) && $arg !== '' ? explode(':', $arg) : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $args
     */
    private static function id(array $payload, array $args): int
    {
        $id = $payload['id'] ?? $args[0] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }

    private function incident(Actor $actor, int $id, IncidentStatus $to): ActionOutcome
    {
        $incident = Incident::query()->forWorkspace($actor->workspace)->find($id);

        if ($incident === null) {
            throw OpsbotException::notFound();
        }

        if ($incident->status === $to) {
            return new ActionOutcome(true, (string) __('opsbot.bot_actions.incident_already', ['number' => DisplayNumber::incident($incident->number)]));
        }

        $this->incidents->update($actor, $incident, ['status' => $to->value], Auditor::SOURCE_BOT);

        return new ActionOutcome(true, (string) __('opsbot.bot_actions.incident_'.$to->value, ['number' => DisplayNumber::incident($incident->number)]));
    }

    private function task(Actor $actor, int $id, TaskStatus $to): ActionOutcome
    {
        $task = Task::query()->forWorkspace($actor->workspace)->find($id);

        if ($task === null) {
            throw OpsbotException::notFound();
        }

        if ($task->status === $to) {
            return new ActionOutcome(true, (string) __('opsbot.bot_actions.task_already', ['number' => DisplayNumber::task($task->number)]));
        }

        $this->tasks->update($actor, $task, ['status' => $to->value], Auditor::SOURCE_BOT);

        return new ActionOutcome(true, (string) __('opsbot.bot_actions.task_'.$to->value, ['number' => DisplayNumber::task($task->number)]));
    }

    private function dismiss(Actor $actor, int $id, string $reason): ActionOutcome
    {
        $question = UnansweredQuestion::query()->forWorkspace($actor->workspace)->find($id);

        if ($question === null) {
            throw OpsbotException::notFound();
        }

        $dismissReason = DismissReason::tryFrom($reason);

        if ($dismissReason === null) {
            return new ActionOutcome(
                true,
                (string) __('opsbot.bot_actions.dismiss_choose'),
                buttons: array_map(fn (DismissReason $case): array => [
                    'text' => $case->label(),
                    'callback' => 'q_dismiss:'.$question->id.':'.$case->value,
                ], DismissReason::cases()),
            );
        }

        $this->escalations->dismiss($actor, $question, $dismissReason, Auditor::SOURCE_BOT);

        return new ActionOutcome(true, (string) __('opsbot.bot_actions.dismissed', ['number' => DisplayNumber::question($question->number)]));
    }

    private function convert(Actor $actor, int $id): ActionOutcome
    {
        $question = UnansweredQuestion::query()->forWorkspace($actor->workspace)->find($id);

        if ($question === null) {
            throw OpsbotException::notFound();
        }

        $question = $this->escalations->convert($actor, $question, null, Auditor::SOURCE_BOT);

        return new ActionOutcome(true, (string) __('opsbot.bot_actions.converted', ['number' => DisplayNumber::question($question->number)]));
    }
}
