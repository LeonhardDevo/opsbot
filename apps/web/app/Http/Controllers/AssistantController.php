<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\AiClient;
use App\Ai\AiServiceException;
use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Domain\Opsbot\Access\Actor;
use App\Domain\Opsbot\Actions\ActionExecutor;
use App\Domain\Opsbot\Actions\AiQueryLogger;
use App\Domain\Opsbot\DepartmentScope;
use App\Domain\Opsbot\Presenters\Refs;
use App\Domain\Opsbot\SubscriptionState;
use App\Domain\Opsbot\Support\SignedLinks;
use App\Domain\Opsbot\TelegramBotService;
use App\Enums\AiChannel;
use App\Enums\RefusalReason;
use App\Enums\SubscriptionState as State;
use App\Http\Controllers\Opsbot\OpsController;
use App\Http\Requests\Assistant\AskRequest;
use App\Models\AiQuery;
use App\Models\AiQueryAction;
use App\Models\Department;
use App\Models\Document;
use App\Support\Paginated;
use App\Support\RequestId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AssistantController extends OpsController
{
    public function index(PlanLimits $limits, TelegramBotService $bots): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'assistant.use');

        /** @var array<string, list<string>> $suggestions */
        $suggestions = config('product.assistant_suggestions', []);
        $delivery = $bots->deliveryBot($actor->workspace);

        return Inertia::render('assistant/Index', [
            'suggestions' => $suggestions[App::getLocale()] ?? $suggestions['en'] ?? [],
            'bot' => ['username' => $delivery['username'] ?? null],
            'usage' => [
                'used' => $limits->used($actor->workspace, 'ai_queries_monthly'),
                'limit' => $limits->limit($actor->workspace, 'ai_queries_monthly'),
            ],
            'minConfidence' => round($actor->workspace->settings()->min_confidence, 2),
        ]);
    }

    public function ask(AskRequest $request, AiClient $ai, AiQueryLogger $logger, RequestId $requestId, SubscriptionState $subscriptions): JsonResponse
    {
        $actor = $this->actor();
        $workspace = $actor->workspace;

        if ($subscriptions->for($workspace) === State::Paused) {
            return response()->json(['message' => __('opsbot.subscription.paused'), 'error' => 'paused'], 423);
        }

        $settings = $workspace->settings();
        $payload = [
            'workspace_id' => $workspace->id,
            'user' => [
                'id' => $actor->id(),
                'role' => $actor->role->value,
                'locale' => App::getLocale(),
                'permissions' => $actor->role->abilities(),
                'department_ids' => $actor->departmentIds,
                'privileged' => $actor->can('knowledge.view_all'),
            ],
            'channel' => AiChannel::Web->value,
            'message' => (string) $request->validated('message'),
            'context' => [
                'today' => $workspace->today(),
                'timezone' => $workspace->zone(),
                'min_confidence' => round($settings->min_confidence, 2),
                'ai_external_allowed' => $workspace->ai_external_allowed,
            ],
        ];

        $conversationId = $request->validated('conversation_id');

        if (is_string($conversationId) && $conversationId !== '') {
            $payload['conversation_id'] = $conversationId;
        }

        try {
            $answer = $ai->chat($payload);
        } catch (AiServiceException $exception) {
            return response()->json([
                'message' => __($exception->isUnavailable() ? 'notifications.assistant.unavailable' : 'notifications.assistant.failed'),
            ], $exception->isUnavailable() ? 503 : 502);
        }

        $refused = (bool) ($answer['refused'] ?? false);
        $citations = array_values(array_filter((array) ($answer['citations'] ?? []), 'is_array'));

        $logged = $logger->log([
            'workspace_id' => $workspace->id,
            'user_id' => $actor->id(),
            'channel' => AiChannel::Web->value,
            'conversation_id' => $payload['conversation_id'] ?? null,
            'question' => $payload['message'],
            'answer' => is_string($answer['answer_markdown'] ?? null) ? $answer['answer_markdown'] : (is_string($answer['answer'] ?? null) ? $answer['answer'] : null),
            'refused' => $refused,
            'confidence' => is_numeric($answer['confidence'] ?? null) ? (float) $answer['confidence'] : null,
            'citations' => $citations,
            'tools_used' => array_values(array_map('strval', (array) ($answer['tools_used'] ?? []))),
            'latency_ms' => is_numeric($answer['latency_ms'] ?? null) ? (int) $answer['latency_ms'] : null,
            'provider' => is_string($answer['provider'] ?? null) ? $answer['provider'] : null,
            'model' => is_string($answer['model'] ?? null) ? $answer['model'] : null,
            'answer_kind' => is_string($answer['answer_kind'] ?? null) ? $answer['answer_kind'] : 'document',
            'role_at_time' => $actor->role->value,
            'department_ids_at_time' => $actor->departmentIds,
            'refusal_reason' => $refused ? ($answer['refusal_reason'] ?? RefusalReason::LowConfidence->value) : null,
            'disclaimers' => array_values(array_map('strval', (array) ($answer['disclaimers'] ?? []))),
            'actions' => array_values(array_filter((array) ($answer['actions'] ?? []), 'is_array')),
        ], $requestId->get());

        $query = $logged['query'];
        $confidence = (float) ($answer['confidence'] ?? 0);
        $high = (float) config('opsbot.confidence.high', 0.70);

        return response()->json([
            'query_id' => $query->id,
            'answer_html' => (string) ($answer['answer_html'] ?? ''),
            'answer_markdown' => (string) ($answer['answer_markdown'] ?? $answer['answer'] ?? ''),
            'citations' => $this->citations($actor, $query),
            'confidence' => round($confidence, 4),
            'confidence_band' => is_string($answer['confidence_band'] ?? null)
                ? $answer['confidence_band']
                : ($refused || $confidence < $settings->min_confidence ? 'low' : ($confidence >= $high ? 'high' : 'medium')),
            'answer_kind' => $query->answer_kind->value,
            'refused' => $refused,
            'refusal_reason' => $query->refusal_reason?->value,
            'disclaimers' => $query->disclaimers,
            'actions' => $logger->proposedActions($query),
            'latency_ms' => (int) ($answer['latency_ms'] ?? 0),
            'provider' => (string) ($answer['provider'] ?? ''),
            'model' => (string) ($answer['model'] ?? ''),
        ]);
    }

    public function action(Request $request, string $action, ActionExecutor $executor): JsonResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'assistant.use');

        $data = $request->validate(['step' => ['required', 'string', Rule::in([ActionExecutor::STEP_PREVIEW, ActionExecutor::STEP_CONFIRM, ActionExecutor::STEP_CANCEL])]]);
        $outcome = $executor->handle($actor, $action, (string) $data['step'], AiChannel::Web);

        return response()->json($outcome->toWeb(), $outcome->ok ? 200 : 422);
    }

    public function history(Request $request, DepartmentScope $scope, Auditor $auditor): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'assistant.history');

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['answered', 'refused', 'escalated'])],
            'channel' => ['nullable', 'string', Rule::in(AiChannel::values())],
            'q' => ['nullable', 'string', 'max:120'],
            'department_id' => ['nullable', 'integer'],
            'refusal_reason' => ['nullable', 'string', Rule::in(RefusalReason::values())],
        ]);

        $base = fn (): Builder => $scope->aiQueries(AiQuery::query()->forWorkspace($actor->workspace), $actor)
            ->when(($filters['status'] ?? null) === 'answered', fn (Builder $query) => $query->where('refused', false))
            ->when(($filters['status'] ?? null) === 'refused', fn (Builder $query) => $query->where('refused', true))
            ->when(($filters['status'] ?? null) === 'escalated', fn (Builder $query) => $query->whereNotNull('escalated_question_id'))
            ->when($filters['channel'] ?? null, fn (Builder $query, string $channel) => $query->where('channel', $channel))
            ->when($filters['refusal_reason'] ?? null, fn (Builder $query, string $reason) => $query->where('refusal_reason', $reason))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int|string $department) => $query->whereRaw('department_ids_at_time @> ARRAY[?]::integer[]', [(int) $department]))
            ->when($filters['q'] ?? null, fn (Builder $query, string $q) => $query->where('question', 'ilike', '%'.addcslashes($q, '%_\\').'%'));

        $page = $base()->with(['user', 'actions'])->latest('id')->paginate(25)->withQueryString();

        $stats = $base()
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where refused = false) as answered')
            ->selectRaw('count(*) filter (where refused = true) as refused')
            ->selectRaw('count(*) filter (where escalated_question_id is not null) as escalated')
            ->selectRaw('coalesce(avg(confidence), 0) as avg_confidence')
            ->toBase()
            ->first();

        $auditor->record('ai_query.viewed', $actor->workspace, metadata: ['filters' => $filters, 'page' => $page->currentPage()], actor: $actor->user, workspace: $actor->workspace, subjectLabel: 'history');

        $departments = Department::query()->forWorkspace($actor->workspace)->get()->keyBy('id');
        $documentState = [];

        return Inertia::render('assistant/History', [
            'queries' => Paginated::from($page, fn (AiQuery $query): array => $this->presentQuery($query, $departments, $documentState)),
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            'stats' => [
                'total' => (int) ($stats->total ?? 0),
                'answered' => (int) ($stats->answered ?? 0),
                'refused' => (int) ($stats->refused ?? 0),
                'escalated' => (int) ($stats->escalated ?? 0),
                'avg_confidence' => round((float) ($stats->avg_confidence ?? 0), 4),
            ],
            'scope' => $scope->aiQueryScope($actor),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function citations(Actor $actor, AiQuery $query): array
    {
        $citations = [];

        foreach ($query->citations as $index => $citation) {
            $documentId = (int) ($citation['document_id'] ?? 0);
            $chunkId = isset($citation['chunk_id']) ? (int) $citation['chunk_id'] : null;
            $page = isset($citation['page']) ? (int) $citation['page'] : null;

            $citations[] = [
                'index' => $index + 1,
                'document_id' => $documentId,
                'title' => (string) ($citation['title'] ?? ''),
                'page' => $page,
                'chunk_id' => (int) $chunkId,
                'snippet' => (string) ($citation['snippet'] ?? ''),
                'version' => isset($citation['version']) ? (string) $citation['version'] : null,
                'url' => $actor->can('knowledge.view')
                    ? route('knowledge.show', ['document' => $documentId], false)
                    : SignedLinks::document($documentId, $actor->id(), $chunkId, $page),
            ];
        }

        return $citations;
    }

    /**
     * @param  Collection<int, Department>  $departments
     * @param  array<int, bool>  $documentState
     * @return array<string, mixed>
     */
    private function presentQuery(AiQuery $query, Collection $departments, array &$documentState): array
    {
        $versions = [];

        foreach ($query->cited_document_versions as $version) {
            $id = (int) ($version['document_id'] ?? 0);

            if (! array_key_exists($id, $documentState)) {
                $documentState[$id] = ! Document::query()->forWorkspace($query->workspace_id)->whereKey($id)->where('approval_status', 'approved')->exists();
            }

            $versions[] = [
                'document_id' => $id,
                'title' => (string) ($version['title'] ?? ''),
                'version' => isset($version['version']) ? (string) $version['version'] : null,
                'effective_date' => isset($version['effective_date']) ? (string) $version['effective_date'] : null,
                'archived_now' => $documentState[$id],
            ];
        }

        $user = $query->user;

        return [
            'id' => $query->id,
            'channel' => $query->channel->value,
            'user' => Refs::user($user),
            'question' => $query->question ?? '',
            'answer' => $query->answer,
            'refused' => $query->refused,
            'confidence' => $query->confidence,
            'citations' => array_map(fn (array $citation): array => [
                'document_id' => (int) ($citation['document_id'] ?? 0),
                'title' => (string) ($citation['title'] ?? ''),
                'page' => isset($citation['page']) ? (int) $citation['page'] : null,
            ], $query->citations),
            'tools_used' => $query->tools_used,
            'latency_ms' => $query->latency_ms,
            'provider' => $query->provider,
            'model' => $query->model,
            'created_at' => $query->created_at->toIso8601String(),
            'bot_key' => $query->bot_key,
            'role_at_time' => $query->role_at_time,
            'departments_at_time' => array_values(array_filter(array_map(
                fn (int $id): ?array => $departments->get($id)?->toRef(),
                $query->department_ids_at_time,
            ))),
            'cited_document_versions' => $versions,
            'refusal_reason' => $query->refusal_reason?->value,
            'escalated_question_id' => $query->escalated_question_id,
            'disclaimers' => $query->disclaimers,
            'actions' => array_values($query->actions->map(fn (AiQueryAction $action): array => [
                'type' => $action->type->value,
                'status' => $action->status->value,
            ])->all()),
            'answer_kind' => $query->answer_kind->value,
            'excerpt' => Str::limit((string) $query->answer, 160),
        ];
    }
}
