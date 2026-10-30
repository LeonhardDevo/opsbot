<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Audit\Auditor;
use App\Domain\Opsbot\DepartmentScope;
use App\Domain\Opsbot\DocumentApprovalService;
use App\Domain\Opsbot\EscalationService;
use App\Domain\Opsbot\IncidentService;
use App\Domain\Opsbot\LimitNotices;
use App\Domain\Opsbot\OpsbotException;
use App\Domain\Opsbot\ReminderDispatcher;
use App\Domain\Opsbot\RoutingResolver;
use App\Domain\Opsbot\SubscriptionState;
use App\Domain\Opsbot\Support\DisplayNumber;
use App\Domain\Opsbot\Support\LocalTime;
use App\Domain\Opsbot\Telegram\InternalActors;
use App\Domain\Opsbot\TelegramBotService;
use App\Enums\IncidentCategory;
use App\Enums\IncidentSource;
use App\Enums\QuestionStatus;
use App\Enums\RoutingCategory;
use App\Enums\SubscriptionState as State;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Locale\WithLocale;
use App\Models\AiQuery;
use App\Models\Incident;
use App\Models\Task;
use App\Models\UnansweredQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class OpsbotController extends Controller
{
    public function __construct(private readonly InternalActors $actors) {}

    public function routing(Request $request, RoutingResolver $routing): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
            'category' => ['nullable', 'string', Rule::enum(RoutingCategory::class)],
            'department_id' => ['nullable', 'integer'],
        ]);

        $actor = $this->actors->fromWorkspaceUser($data['workspace_id'], $data['user_id']);
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : app(DepartmentScope::class)->primaryDepartmentId($actor->user, $actor->workspaceId());
        $result = $routing->resolve($actor->workspace, RoutingCategory::from((string) ($data['category'] ?? RoutingCategory::Task->value)), $departmentId);

        return response()->json([
            'user' => $result['user'] !== null ? ['id' => $result['user']->id, 'name' => $result['user']->displayName()] : null,
            'department' => $result['department'] !== null ? ['id' => $result['department']->id, 'name' => $result['department']->name] : null,
            'source' => $result['source'],
        ]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required_without:bot_key', 'nullable', 'integer'],
            'user_id' => ['required_without:bot_key', 'nullable', 'integer'],
            'bot_key' => ['nullable', 'string'],
            'telegram_user_id' => ['required_with:bot_key', 'nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in(['open', 'overdue'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $actor = isset($data['bot_key'])
            ? $this->actors->fromTelegram($data['bot_key'], $data['telegram_user_id'] ?? null)['actor']
            : $this->actors->fromWorkspaceUser($data['workspace_id'] ?? null, $data['user_id'] ?? null);

        if (! $actor->can('tasks.view_own')) {
            throw OpsbotException::forbidden();
        }

        $now = CarbonImmutable::now();
        $locale = $actor->locale();
        $tz = $actor->workspace->zone();

        $tasks = Task::query()
            ->forWorkspace($actor->workspace)
            ->where('assignee_id', $actor->id())
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->when(($data['status'] ?? 'open') === 'overdue', fn ($query) => $query->where('due_at', '<', $now))
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit((int) ($data['limit'] ?? 10))
            ->get();

        return response()->json([
            'tasks' => WithLocale::run($locale, fn (): array => $tasks->map(fn (Task $task): array => [
                'id' => $task->id,
                'display_number' => DisplayNumber::task($task->number),
                'title' => $task->title,
                'status' => $task->status->value,
                'due_at' => $task->due_at?->toIso8601String(),
                'due_local' => LocalTime::dateTime($task->due_at, $tz, $locale),
                'overdue' => $task->isOverdue($now),
            ])->values()->all()),
        ]);
    }

    public function incident(Request $request, int $number, DepartmentScope $scope): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
        ]);

        $actor = $this->actors->fromWorkspaceUser($data['workspace_id'], $data['user_id']);
        $incident = Incident::query()->forWorkspace($actor->workspace)->where('number', $number)->with('assignee')->first();

        if ($incident === null || ! $scope->canViewIncident($actor, $incident)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $locale = $actor->locale();

        return response()->json(WithLocale::run($locale, fn (): array => [
            'display_number' => DisplayNumber::incident($incident->number),
            'category' => $incident->category->value,
            'status' => $incident->status->value,
            'assignee_name' => $incident->assignee?->displayName(),
            'updated_local' => LocalTime::dateTime($incident->updated_at, $actor->workspace->zone(), $locale),
        ]));
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bot_key' => ['required', 'string'],
            'telegram_user_id' => ['required', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        ['actor' => $actor] = $this->actors->fromTelegram($data['bot_key'], $data['telegram_user_id']);
        $locale = $actor->locale();
        $tz = $actor->workspace->zone();

        $queries = AiQuery::query()
            ->forWorkspace($actor->workspace)
            ->where('user_id', $actor->id())
            ->whereNotNull('question')
            ->with('escalatedQuestion')
            ->orderByDesc('id')
            ->limit((int) ($data['limit'] ?? 10))
            ->get();

        return response()->json([
            'items' => $queries->map(fn (AiQuery $query): array => [
                'asked_local' => LocalTime::dateTime($query->created_at, $tz, $locale),
                'question' => Str::limit((string) $query->question, 300),
                'refused' => $query->refused,
                'manager_answered' => $query->escalatedQuestion !== null && in_array($query->escalatedQuestion->status, [QuestionStatus::Answered, QuestionStatus::ConvertedToDoc], true),
            ])->values()->all(),
        ]);
    }

    public function queue(Request $request, DepartmentScope $scope): JsonResponse
    {
        $data = $request->validate([
            'bot_key' => ['required', 'string'],
            'telegram_user_id' => ['required', 'integer'],
        ]);

        ['actor' => $actor] = $this->actors->fromTelegram($data['bot_key'], $data['telegram_user_id']);

        if (! $actor->can('questions.answer')) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $now = CarbonImmutable::now();
        $questions = UnansweredQuestion::query()
            ->forWorkspace($actor->workspace)
            ->where('status', QuestionStatus::Open->value)
            ->where('assignee_user_id', $actor->id())
            ->with('employee')
            ->orderBy('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'items' => WithLocale::run($actor->locale(), fn (): array => $questions->map(fn (UnansweredQuestion $question): array => [
                'id' => $question->id,
                'display_number' => DisplayNumber::question($question->number),
                'question_excerpt' => Str::limit($question->question, 200),
                'employee_name' => $question->employee->displayName(),
                'age_hours' => LocalTime::ageHours($question->created_at, $now),
            ])->values()->all()),
        ]);
    }

    public function createIncident(Request $request, IncidentService $incidents): JsonResponse
    {
        $data = $request->validate([
            'bot_key' => ['required', 'string'],
            'telegram_user_id' => ['required', 'integer'],
            'category' => ['required', 'string', Rule::enum(IncidentCategory::class)],
            'description' => ['required', 'string', 'min:1', 'max:1000'],
            'client_ref' => ['nullable', 'string', 'max:120'],
        ]);

        ['actor' => $actor] = $this->actors->fromTelegram($data['bot_key'], $data['telegram_user_id']);

        return WithLocale::run($actor->locale(), function () use ($actor, $data, $incidents): JsonResponse {
            $incident = $incidents->create($actor, [
                'category' => (string) $data['category'],
                'description' => (string) $data['description'],
                'client_ref' => $data['client_ref'] ?? null,
            ], IncidentSource::Bot, auditSource: Auditor::SOURCE_BOT);

            $number = DisplayNumber::incident($incident->number);
            $assignee = $incident->assignee;

            return response()->json([
                'incident' => ['id' => $incident->id, 'display_number' => $number],
                'message_html' => (string) __('opsbot.bot_actions.incident_created_html', [
                    'number' => e($number),
                    'assignee' => e($assignee?->displayName() ?? '—'),
                ]),
            ], 201);
        });
    }

    public function escalateLast(Request $request, EscalationService $escalations, SubscriptionState $subscriptions): JsonResponse
    {
        $data = $request->validate([
            'bot_key' => ['required', 'string'],
            'telegram_user_id' => ['required', 'integer'],
        ]);

        ['actor' => $actor, 'account' => $account] = $this->actors->fromTelegram($data['bot_key'], $data['telegram_user_id']);

        return WithLocale::run($actor->locale(), function () use ($actor, $account, $escalations, $subscriptions): JsonResponse {
            if ($subscriptions->for($actor->workspace) === State::Paused) {
                return response()->json(['ok' => false, 'message_html' => e((string) __('opsbot.subscription.paused'))]);
            }

            $query = AiQuery::query()
                ->forWorkspace($actor->workspace)
                ->where('user_id', $actor->id())
                ->where('bot_key', $account->bot_key)
                ->whereNotNull('question')
                ->orderByDesc('id')
                ->first();

            if ($query === null) {
                return response()->json(['ok' => false, 'message_html' => e((string) __('opsbot.bot_actions.nothing_to_escalate'))]);
            }

            $question = $escalations->escalate($actor, $query, null, Auditor::SOURCE_BOT);
            $assignee = $question->assignee;

            return response()->json([
                'ok' => true,
                'message_html' => (string) __('opsbot.bot_actions.escalated_html', [
                    'number' => e(DisplayNumber::question($question->number)),
                    'assignee' => e($assignee?->displayName() ?? '—'),
                ]),
            ]);
        });
    }

    public function answerQuestion(Request $request, int $question, EscalationService $escalations): JsonResponse
    {
        $data = $request->validate([
            'bot_key' => ['required', 'string'],
            'telegram_user_id' => ['required', 'integer'],
            'text' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        ['actor' => $actor] = $this->actors->fromTelegram($data['bot_key'], $data['telegram_user_id']);

        return WithLocale::run($actor->locale(), function () use ($actor, $question, $data, $escalations): JsonResponse {
            $model = UnansweredQuestion::query()->forWorkspace($actor->workspace)->find($question);

            if ($model === null) {
                return response()->json(['ok' => false, 'message_html' => e((string) __('errors.not_found'))], 404);
            }

            $model = $escalations->answer($actor, $model, (string) $data['text'], Auditor::SOURCE_BOT);
            $buttons = $actor->can('knowledge.manage')
                ? [['text' => (string) __('opsbot.buttons.convert_faq'), 'callback' => 'q_convert:'.$model->id]]
                : [];

            return response()->json(array_filter([
                'ok' => true,
                'message_html' => (string) __('opsbot.bot_actions.answered_html', ['number' => e(DisplayNumber::question($model->number))]),
                'buttons' => $buttons !== [] ? $buttons : null,
            ], fn (mixed $value): bool => $value !== null));
        });
    }

    public function remindersDispatch(ReminderDispatcher $dispatcher): JsonResponse
    {
        return response()->json($dispatcher->dispatch());
    }

    public function documentsTick(DocumentApprovalService $approvals): JsonResponse
    {
        return response()->json($approvals->tick());
    }

    public function limitsTick(LimitNotices $notices): JsonResponse
    {
        return response()->json(['notices' => $notices->tick()]);
    }

    public function botsReconcile(TelegramBotService $bots): JsonResponse
    {
        return response()->json(['finalized' => $bots->reconcile()]);
    }
}
