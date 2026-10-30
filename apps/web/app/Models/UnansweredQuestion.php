<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DismissReason;
use App\Enums\QuestionReason;
use App\Enums\QuestionStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $number
 * @property int|null $ai_query_id
 * @property string $question
 * @property int $employee_user_id
 * @property int|null $department_id
 * @property int|null $assignee_user_id
 * @property QuestionReason $reason
 * @property QuestionStatus $status
 * @property string|null $answer_text
 * @property int|null $answered_by
 * @property CarbonInterface|null $answered_at
 * @property DismissReason|null $dismissed_reason
 * @property int|null $dismissed_by
 * @property CarbonInterface|null $dismissed_at
 * @property int|null $document_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read User $employee
 * @property-read User|null $assignee
 * @property-read User|null $answerer
 * @property-read Department|null $department
 * @property-read Document|null $document
 * @property-read AiQuery|null $aiQuery
 */
class UnansweredQuestion extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'number', 'ai_query_id', 'question', 'employee_user_id', 'department_id',
        'assignee_user_id', 'reason', 'status', 'answer_text', 'answered_by', 'answered_at', 'dismissed_reason',
        'dismissed_by', 'dismissed_at', 'document_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => QuestionReason::class,
            'status' => QuestionStatus::class,
            'dismissed_reason' => DismissReason::class,
            'answered_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<AiQuery, $this> */
    public function aiQuery(): BelongsTo
    {
        return $this->belongsTo(AiQuery::class)->withoutGlobalScopes();
    }
}
