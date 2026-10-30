<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PgIntegerArray;
use App\Enums\AiChannel;
use App\Enums\AnswerKind;
use App\Enums\RefusalReason;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property AiChannel $channel
 * @property string|null $question
 * @property string|null $answer
 * @property bool $refused
 * @property float|null $confidence
 * @property list<array<string, mixed>> $citations
 * @property list<string> $tools_used
 * @property int|null $latency_ms
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $request_id
 * @property AnswerKind $answer_kind
 * @property string|null $bot_key
 * @property string|null $conversation_id
 * @property string|null $role_at_time
 * @property list<int> $department_ids_at_time
 * @property list<array<string, mixed>> $cited_document_versions
 * @property RefusalReason|null $refusal_reason
 * @property int|null $escalated_question_id
 * @property list<string> $disclaimers
 * @property string|null $question_normalized
 * @property CarbonInterface $created_at
 * @property-read User|null $user
 * @property-read Collection<int, AiQueryAction> $actions
 */
class AiQuery extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'user_id', 'channel', 'question', 'answer', 'refused', 'confidence', 'citations',
        'tools_used', 'latency_ms', 'provider', 'model', 'request_id', 'answer_kind', 'bot_key', 'conversation_id',
        'role_at_time', 'department_ids_at_time', 'cited_document_versions', 'refusal_reason',
        'escalated_question_id', 'disclaimers', 'created_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'answer_kind' => 'document',
        'department_ids_at_time' => '{}',
        'cited_document_versions' => '[]',
        'disclaimers' => '[]',
        'citations' => '[]',
        'tools_used' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => AiChannel::class,
            'answer_kind' => AnswerKind::class,
            'refusal_reason' => RefusalReason::class,
            'refused' => 'boolean',
            'confidence' => 'float',
            'citations' => 'array',
            'tools_used' => 'array',
            'cited_document_versions' => 'array',
            'disclaimers' => 'array',
            'department_ids_at_time' => PgIntegerArray::class,
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AiQueryAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(AiQueryAction::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<UnansweredQuestion, $this> */
    public function escalatedQuestion(): BelongsTo
    {
        return $this->belongsTo(UnansweredQuestion::class, 'escalated_question_id')->withoutGlobalScopes();
    }
}
