<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Presenters;

use App\Domain\Opsbot\Support\DisplayNumber;
use App\Domain\Opsbot\Support\LocalTime;
use App\Models\UnansweredQuestion;
use Carbon\CarbonInterface;

final class QuestionPresenter
{
    /** @return list<string> */
    public static function relations(): array
    {
        return ['employee', 'assignee', 'answerer', 'department', 'document'];
    }

    /** @return array<string, mixed> */
    public static function present(UnansweredQuestion $question, int $similarCount = 0, ?CarbonInterface $now = null): array
    {
        $document = $question->document;

        return [
            'id' => $question->id,
            'number' => $question->number,
            'display_number' => DisplayNumber::question($question->number),
            'question' => $question->question,
            'reason' => $question->reason->value,
            'status' => $question->status->value,
            'employee' => $question->employee->toRef(),
            'department' => Refs::department($question->department),
            'assignee' => Refs::user($question->assignee),
            'asked_at' => $question->created_at->toIso8601String(),
            'age_hours' => LocalTime::ageHours($question->created_at, $now),
            'answer_text' => $question->answer_text,
            'answered_by' => Refs::user($question->answerer),
            'answered_at' => $question->answered_at?->toIso8601String(),
            'document' => $document !== null ? ['id' => $document->id, 'title' => $document->title] : null,
            'similar_count' => $similarCount,
            'ai_query_id' => $question->ai_query_id,
        ];
    }
}
