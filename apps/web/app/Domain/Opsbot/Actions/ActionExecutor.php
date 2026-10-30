<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Actions;

use App\Audit\Auditor;
use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\EscalationService;
use App\Domain\Opsbot\IncidentService;
use App\Domain\Opsbot\OpsbotException;
use App\Domain\Opsbot\Support\DisplayNumber;
use App\Domain\Opsbot\Support\SignedLinks;
use App\Domain\Opsbot\TaskService;
use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Enums\AiChannel;
use App\Enums\IncidentCategory;
use App\Enums\IncidentSource;
use App\Enums\QuestionReason;
use App\Enums\Severity;
use App\Enums\TaskSource;
use App\Models\AiQuery;
use App\Models\AiQueryAction;
use App\Models\Incident;
use App\Models\Task;
use App\Models\UnansweredQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final readonly class ActionExecutor
{
    public const string STEP_PREVIEW = 'preview';

    public const string STEP_CONFIRM = 'confirm';

    public const string STEP_CANCEL = 'cancel';

    public function __construct(
        private IncidentService $incidents,
        private TaskService $tasks,
        private EscalationService $escalations,
    ) {}

    public function handle(Actor $actor, string $actionId, string $step, AiChannel $channel): ActionOutcome
    {
        if (! Str::isUuid($actionId)) {
            return ActionOutcome::failure((string) __('opsbot.actions_result.not_found'));
        }

        $action = AiQueryAction::query()->forWorkspace($actor->workspace)->find($actionId);

        if ($action === null) {
            return ActionOutcome::failure((string) __('opsbot.actions_result.not_found'));
        }

        if ($action->user_id !== $actor->id()) {
            return ActionOutcome::failure((string) __('opsbot.actions_result.foreign'));
        }

        return match ($step) {
            self::STEP_CANCEL => $this->cancel($actor, $action),
            self::STEP_PREVIEW => $this->preview($actor, $action, $channel),
            self::STEP_CONFIRM => $this->confirm($actor, $action, $channel),
            default => ActionOutcome::failure((string) __('opsbot.actions_result.unavailable')),
        };
    }

    private function cancel(Actor $actor, AiQueryAction $action): ActionOutcome
    {
        return DB::transaction(function () use ($actor, $action): ActionOutcome {
            $action = AiQueryAction::query()->forWorkspace($actor->workspace)->whereKey($action->id)->lockForUpdate()->firstOrFail();

            if ($action->status === ActionStatus::Proposed) {
                $action->forceFill(['status' => ActionStatus::Cancelled])->save();
            }

            return new ActionOutcome(true, (string) __('opsbot.actions_result.cancelled'));
        });
    }

    private function preview(Actor $actor, AiQueryAction $action, AiChannel $channel): ActionOutcome
    {
        $blocked = $this->blocked($action);

        if ($blocked !== null) {
            return $blocked;
        }

        if (in_array($action->type, [ActionType::Escalate, ActionType::OpenSource], true)) {
            return $this->confirm($actor, $action, $channel);
        }

        if ($action->type === ActionType::Ask) {
            return ActionOutcome::failure((string) __('opsbot.actions_result.unavailable'));
        }

        $payload = $action->payload;

        if ($action->type === ActionType::CreateIncident) {
            $category = IncidentCategory::from((string) $payload['category']);
            $severity = isset($payload['severity']) ? Severity::from((string) $payload['severity']) : IncidentService::defaultSeverity($category);
            $fields = [
                ['label' => (string) __('opsbot.card.category'), 'value' => $category->label()],
                ['label' => (string) __('opsbot.card.severity'), 'value' => $severity->label()],
                ['label' => (string) __('opsbot.card.description'), 'value' => (string) $payload['description']],
            ];

            if (isset($payload['client_ref'])) {
                $fields[] = ['label' => (string) __('opsbot.card.client_ref'), 'value' => (string) $payload['client_ref']];
            }

            $title = (string) __('opsbot.card.incident_title');
        } else {
            $fields = [['label' => (string) __('opsbot.card.title'), 'value' => (string) $payload['title']]];

            if (isset($payload['description'])) {
                $fields[] = ['label' => (string) __('opsbot.card.description'), 'value' => (string) $payload['description']];
            }

            if (isset($payload['due_in_hours'])) {
                $hours = (int) $payload['due_in_hours'];
                $fields[] = ['label' => (string) __('opsbot.card.due'), 'value' => trans_choice('opsbot.card.due_in_hours', $hours, ['count' => $hours])];
            }

            $title = (string) __('opsbot.card.task_title');
        }

        return new ActionOutcome(
            true,
            (string) __('opsbot.actions_result.confirm_prompt'),
            card: ['title' => $title, 'fields' => $fields],
            buttons: [
                ['text' => (string) __('opsbot.buttons.confirm'), 'callback' => 'opsbot.action:'.$action->id.':confirm'],
                ['text' => (string) __('opsbot.buttons.cancel'), 'callback' => 'opsbot.action:'.$action->id.':cancel'],
            ],
        );
    }

    private function confirm(Actor $actor, AiQueryAction $action, AiChannel $channel): ActionOutcome
    {
        $creates = in_array($action->type, [ActionType::CreateIncident, ActionType::CreateTask], true);
        $limiterKey = 'opsbot-actions:'.$actor->id();

        if ($creates && $action->status === ActionStatus::Proposed && RateLimiter::tooManyAttempts($limiterKey, (int) config('opsbot.actions.executions_per_hour', 5))) {
            return ActionOutcome::failure((string) __('opsbot.actions_result.rate_limited'));
        }

        $executed = false;

        $outcome = DB::transaction(function () use ($actor, $action, $channel, &$executed): ActionOutcome {
            $action = AiQueryAction::query()->forWorkspace($actor->workspace)->whereKey($action->id)->lockForUpdate()->firstOrFail();

            $blocked = $this->blocked($action);

            if ($blocked !== null) {
                return $blocked;
            }

            $query = AiQuery::query()->forWorkspace($actor->workspace)->findOrFail($action->ai_query_id);

            try {
                [$resultType, $resultId, $outcome] = $this->execute($actor, $action, $query, $channel);
            } catch (OpsbotException $exception) {
                $action->forceFill(['status' => ActionStatus::Failed, 'error_code' => Str::limit($exception->key, 32, '')])->save();

                return ActionOutcome::failure($exception->userMessage());
            }

            $action->forceFill([
                'status' => ActionStatus::Executed,
                'result_type' => $resultType,
                'result_id' => $resultId,
                'executed_at' => now(),
            ])->save();
            $executed = true;

            return $outcome;
        });

        if ($creates && $executed) {
            RateLimiter::hit($limiterKey, 3600);
        }

        return $outcome;
    }

    /** @return array{0: string|null, 1: int|null, 2: ActionOutcome} */
    private function execute(Actor $actor, AiQueryAction $action, AiQuery $query, AiChannel $channel): array
    {
        $payload = $action->payload;
        $auditSource = Auditor::SOURCE_AI_PROPOSAL;

        return match ($action->type) {
            ActionType::CreateIncident => (function () use ($actor, $payload, $query, $action, $auditSource): array {
                $incident = $this->incidents->create($actor, [
                    'category' => (string) $payload['category'],
                    'severity' => isset($payload['severity']) ? (string) $payload['severity'] : null,
                    'description' => (string) $payload['description'],
                    'client_ref' => isset($payload['client_ref']) ? (string) $payload['client_ref'] : null,
                ], IncidentSource::AiProposal, $query->id, $action->id, $auditSource);

                return ['incident', $incident->id, $this->incidentOutcome($incident, false)];
            })(),
            ActionType::CreateTask => (function () use ($actor, $payload, $query, $auditSource): array {
                $task = $this->tasks->create($actor, [
                    'title' => (string) $payload['title'],
                    'description' => isset($payload['description']) ? (string) $payload['description'] : null,
                    'due_at' => isset($payload['due_in_hours']) ? now()->addHours((int) $payload['due_in_hours'])->toImmutable() : null,
                ], TaskSource::AiProposal, null, $query->id, $auditSource);

                return ['task', $task->id, $this->taskOutcome($task, false)];
            })(),
            ActionType::Escalate => (function () use ($actor, $payload, $query, $auditSource): array {
                $question = $this->escalations->escalate($actor, $query, QuestionReason::tryFrom((string) ($payload['reason'] ?? '')), $auditSource);

                return ['question', $question->id, $this->questionOutcome($question)];
            })(),
            ActionType::OpenSource => [null, null, new ActionOutcome(
                true,
                (string) __('opsbot.actions_result.source_ready'),
                buttons: [['text' => (string) __('opsbot.buttons.open_source'), 'url' => SignedLinks::document(
                    (int) $payload['document_id'],
                    $actor->id(),
                    isset($payload['chunk_id']) ? (int) $payload['chunk_id'] : null,
                    isset($payload['page']) ? (int) $payload['page'] : null,
                )]],
            )],
            ActionType::Ask => throw new OpsbotException('actions.unavailable'),
        };
    }

    private function blocked(AiQueryAction $action): ?ActionOutcome
    {
        if ($action->status === ActionStatus::Executed) {
            return $this->existingResult($action);
        }

        if ($action->status === ActionStatus::Proposed && $action->expires_at->isPast()) {
            $action->forceFill(['status' => ActionStatus::Expired])->save();

            return ActionOutcome::failure((string) __('opsbot.actions_result.expired'));
        }

        return match ($action->status) {
            ActionStatus::Expired => ActionOutcome::failure((string) __('opsbot.actions_result.expired')),
            ActionStatus::Cancelled, ActionStatus::Failed => ActionOutcome::failure((string) __('opsbot.actions_result.unavailable')),
            default => null,
        };
    }

    private function existingResult(AiQueryAction $action): ActionOutcome
    {
        return match ($action->result_type) {
            'incident' => ($incident = Incident::query()->withoutGlobalScopes()->find($action->result_id)) !== null
                ? $this->incidentOutcome($incident, true)
                : new ActionOutcome(true, (string) __('opsbot.actions_result.done')),
            'task' => ($task = Task::query()->withoutGlobalScopes()->find($action->result_id)) !== null
                ? $this->taskOutcome($task, true)
                : new ActionOutcome(true, (string) __('opsbot.actions_result.done')),
            'question' => ($question = UnansweredQuestion::query()->withoutGlobalScopes()->find($action->result_id)) !== null
                ? $this->questionOutcome($question)
                : new ActionOutcome(true, (string) __('opsbot.actions_result.done')),
            default => new ActionOutcome(true, (string) __('opsbot.actions_result.done')),
        };
    }

    private function incidentOutcome(Incident $incident, bool $existing): ActionOutcome
    {
        $number = DisplayNumber::incident($incident->number);
        $assignee = $incident->assignee;
        $message = (string) __($existing ? 'opsbot.actions_result.incident_exists' : 'opsbot.actions_result.incident_created', [
            'number' => $number,
            'assignee' => $assignee?->displayName() ?? '—',
        ]);

        return new ActionOutcome(true, $message, result: [
            'type' => 'incident',
            'id' => $incident->id,
            'display_number' => $number,
            'url' => route('incidents.show', ['incident' => $incident->id], false),
        ]);
    }

    private function taskOutcome(Task $task, bool $existing): ActionOutcome
    {
        $number = DisplayNumber::task($task->number);
        $assignee = $task->assignee;
        $message = (string) __($existing ? 'opsbot.actions_result.task_exists' : 'opsbot.actions_result.task_created', [
            'number' => $number,
            'assignee' => $assignee?->displayName() ?? '—',
        ]);

        return new ActionOutcome(true, $message, result: [
            'type' => 'task',
            'id' => $task->id,
            'display_number' => $number,
            'url' => route('tasks.index', ['focus' => $task->id], false),
        ]);
    }

    private function questionOutcome(UnansweredQuestion $question): ActionOutcome
    {
        $number = DisplayNumber::question($question->number);
        $assignee = $question->assignee;

        return new ActionOutcome(true, (string) __('opsbot.actions_result.escalated', [
            'number' => $number,
            'assignee' => $assignee?->displayName() ?? '—',
        ]), result: [
            'type' => 'question',
            'id' => $question->id,
            'display_number' => $number,
            'url' => route('unanswered.index', ['focus' => $question->id], false),
        ]);
    }
}
