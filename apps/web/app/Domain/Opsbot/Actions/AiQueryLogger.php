<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Actions;

use App\Domain\Opsbot\Support\SignedLinks;
use App\Enums\ActionStatus;
use App\Enums\ActionType;
use App\Enums\AiChannel;
use App\Enums\AnswerKind;
use App\Enums\QuestionReason;
use App\Enums\RefusalReason;
use App\Locale\WithLocale;
use App\Models\AiQuery;
use App\Models\AiQueryAction;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AiQueryLogger
{
    private const array ORDER = [
        'create_incident' => 0,
        'create_task' => 0,
        'escalate' => 1,
        'open_source' => 2,
        'ask' => 3,
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array{query: AiQuery, created: bool}
     */
    public function log(array $data, ?string $requestId): array
    {
        $workspaceId = (int) $data['workspace_id'];
        $attributes = $this->attributes($data, $workspaceId);

        $existing = $requestId !== null
            ? AiQuery::query()->forWorkspace($workspaceId)->where('request_id', $requestId)->first()
            : null;

        if ($existing !== null) {
            return ['query' => $this->enrich($existing, $attributes, $data), 'created' => false];
        }

        try {
            $query = DB::transaction(function () use ($attributes, $requestId, $data): AiQuery {
                $query = new AiQuery;
                $query->fill([...$attributes, 'request_id' => $requestId]);
                $query->save();

                $this->storeActions($query, $data);

                return $query;
            });
        } catch (UniqueConstraintViolationException) {
            $query = AiQuery::query()->withoutGlobalScopes()->where('request_id', $requestId)->firstOrFail();

            return ['query' => $this->enrich($query, $attributes, $data), 'created' => false];
        }

        return ['query' => $query, 'created' => true];
    }

    /** @return list<array{id: string, type: string, label: string, url: string|null, status: string}> */
    public function proposedActions(AiQuery $query): array
    {
        $user = $query->user_id !== null ? User::query()->find($query->user_id) : null;

        return array_values($query->actions()
            ->orderBy('created_at')
            ->get()
            ->sortBy(fn (AiQueryAction $action): int => self::ORDER[$action->type->value])
            ->map(fn (AiQueryAction $action): array => [
                'id' => $action->id,
                'type' => $action->type->value,
                'label' => $action->label,
                'url' => $action->type === ActionType::OpenSource && $user !== null
                    ? SignedLinks::document(
                        (int) $action->payload['document_id'],
                        $user->id,
                        isset($action->payload['chunk_id']) ? (int) $action->payload['chunk_id'] : null,
                        isset($action->payload['page']) ? (int) $action->payload['page'] : null,
                    )
                    : null,
                'status' => $this->effectiveStatus($action)->value,
            ])
            ->values()
            ->all());
    }

    /** @return list<array{index: int, url: string|null}> */
    public function citationUrls(AiQuery $query): array
    {
        $urls = [];

        foreach ($query->citations as $index => $citation) {
            $urls[] = [
                'index' => $index + 1,
                'url' => $query->user_id !== null && is_numeric($citation['document_id'] ?? null)
                    ? SignedLinks::document(
                        (int) $citation['document_id'],
                        $query->user_id,
                        is_numeric($citation['chunk_id'] ?? null) ? (int) $citation['chunk_id'] : null,
                        is_numeric($citation['page'] ?? null) ? (int) $citation['page'] : null,
                    )
                    : null,
            ];
        }

        return $urls;
    }

    public function effectiveStatus(AiQueryAction $action): ActionStatus
    {
        if ($action->status === ActionStatus::Proposed && $action->expires_at->isPast()) {
            return ActionStatus::Expired;
        }

        return $action->status;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, int $workspaceId): array
    {
        $citations = [];
        $documentIds = [];

        foreach ((array) ($data['citations'] ?? []) as $citation) {
            if (! is_array($citation) || ! is_numeric($citation['document_id'] ?? null)) {
                continue;
            }

            $documentIds[] = (int) $citation['document_id'];
            $citations[] = array_filter([
                'document_id' => (int) $citation['document_id'],
                'chunk_id' => is_numeric($citation['chunk_id'] ?? null) ? (int) $citation['chunk_id'] : null,
                'page' => is_numeric($citation['page'] ?? null) ? (int) $citation['page'] : null,
                'version' => isset($citation['version']) ? (string) $citation['version'] : null,
                'title' => Str::limit((string) ($citation['title'] ?? ''), 500, ''),
                'snippet' => isset($citation['snippet']) ? Str::limit((string) $citation['snippet'], 600) : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        $documents = Document::query()->forWorkspace($workspaceId)->whereIn('id', array_values(array_unique($documentIds)))->get()->keyBy('id');
        $versions = [];

        foreach (array_values(array_unique($documentIds)) as $documentId) {
            $document = $documents->get($documentId);

            if ($document === null) {
                continue;
            }

            $versions[] = [
                'document_id' => $document->id,
                'title' => $document->title,
                'version' => $document->version,
                'approval_status' => $document->approval_status->value,
                'effective_date' => $document->effective_date?->toDateString(),
            ];
        }

        $refused = (bool) ($data['refused'] ?? false);

        return [
            'workspace_id' => $workspaceId,
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'channel' => AiChannel::from((string) $data['channel']),
            'question' => (string) $data['question'],
            'answer' => $data['answer'] ?? null,
            'refused' => $refused,
            'confidence' => isset($data['confidence']) ? (float) $data['confidence'] : null,
            'citations' => $citations,
            'tools_used' => array_values((array) ($data['tools_used'] ?? [])),
            'latency_ms' => isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
            'provider' => $data['provider'] ?? null,
            'model' => $data['model'] ?? null,
            'answer_kind' => AnswerKind::tryFrom((string) ($data['answer_kind'] ?? 'document')) ?? AnswerKind::Document,
            'bot_key' => $data['bot_key'] ?? null,
            'conversation_id' => $data['conversation_id'] ?? null,
            'role_at_time' => $data['role_at_time'] ?? null,
            'department_ids_at_time' => (array) ($data['department_ids_at_time'] ?? []),
            'cited_document_versions' => $versions,
            'refusal_reason' => $refused ? (RefusalReason::tryFrom((string) ($data['refusal_reason'] ?? '')) ?? RefusalReason::LowConfidence) : null,
            'disclaimers' => array_values(array_map('strval', (array) ($data['disclaimers'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $data
     */
    private function enrich(AiQuery $query, array $attributes, array $data): AiQuery
    {
        return DB::transaction(function () use ($query, $attributes, $data): AiQuery {
            foreach (['role_at_time', 'bot_key', 'conversation_id'] as $field) {
                if ($query->getAttribute($field) === null && $attributes[$field] !== null) {
                    $query->setAttribute($field, $attributes[$field]);
                }
            }

            if ($query->department_ids_at_time === [] && $attributes['department_ids_at_time'] !== []) {
                $query->department_ids_at_time = $attributes['department_ids_at_time'];
            }

            if ($query->cited_document_versions === [] && $attributes['cited_document_versions'] !== []) {
                $query->cited_document_versions = $attributes['cited_document_versions'];
                $query->citations = $attributes['citations'];
            }

            if ($query->disclaimers === [] && $attributes['disclaimers'] !== []) {
                $query->disclaimers = $attributes['disclaimers'];
            }

            if ($query->refused && $query->refusal_reason === null) {
                $query->refusal_reason = $attributes['refusal_reason'];
            }

            if (isset($data['answer_kind'])) {
                $query->answer_kind = $attributes['answer_kind'];
            }

            $query->save();

            if (! $query->actions()->exists()) {
                $this->storeActions($query, $data);
            }

            return $query;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function storeActions(AiQuery $query, array $data): void
    {
        $user = $query->user_id !== null ? User::query()->find($query->user_id) : null;
        $locale = $user?->preferredLocale() ?? 'en';
        $actions = [];

        foreach ((array) ($data['actions'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $type = ActionType::tryFrom((string) ($raw['type'] ?? ''));
            $payload = $type !== null ? ActionPayloads::normalize($type, is_array($raw['payload'] ?? null) ? $raw['payload'] : []) : null;

            if ($type === null || $payload === null) {
                continue;
            }

            if ($type === ActionType::Escalate && $payload['reason'] === QuestionReason::LimitReached->value && $query->refusal_reason !== RefusalReason::LimitReached) {
                continue;
            }

            $actions[] = ['type' => $type, 'payload' => $payload, 'label' => trim((string) ($raw['label'] ?? ''))];
        }

        if ($query->refused && ! in_array(ActionType::Escalate, array_column($actions, 'type'), true)) {
            $actions[] = [
                'type' => ActionType::Escalate,
                'payload' => ['reason' => $query->refusal_reason === RefusalReason::LimitReached ? 'limit_reached' : ($query->refusal_reason === RefusalReason::NotFound ? 'not_found' : 'low_confidence')],
                'label' => '',
            ];
        }

        usort($actions, fn (array $a, array $b): int => self::ORDER[$a['type']->value] <=> self::ORDER[$b['type']->value]);
        $actions = array_slice($actions, 0, (int) config('opsbot.actions.max_per_answer', 4));

        foreach ($actions as $action) {
            $label = $action['label'] !== ''
                ? Str::limit($action['label'], 64, '')
                : WithLocale::run($locale, fn (): string => Str::limit((string) __('opsbot.actions.'.$action['type']->value), 64, ''));

            AiQueryAction::query()->create([
                'workspace_id' => $query->workspace_id,
                'ai_query_id' => $query->id,
                'user_id' => $query->user_id,
                'type' => $action['type'],
                'label' => $label,
                'payload' => $action['payload'],
                'status' => ActionStatus::Proposed,
                'expires_at' => now()->addHours((int) config('opsbot.actions.ttl_hours', 24)),
            ]);
        }
    }
}
