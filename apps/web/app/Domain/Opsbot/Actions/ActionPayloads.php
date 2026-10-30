<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Actions;

use App\Enums\ActionType;
use App\Enums\IncidentCategory;
use App\Enums\QuestionReason;
use App\Enums\Severity;
use Illuminate\Support\Str;

final class ActionPayloads
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public static function normalize(ActionType $type, array $payload): ?array
    {
        return match ($type) {
            ActionType::CreateIncident => self::incident($payload),
            ActionType::CreateTask => self::task($payload),
            ActionType::Escalate => self::escalate($payload),
            ActionType::OpenSource => self::openSource($payload),
            ActionType::Ask => self::ask($payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function incident(array $payload): ?array
    {
        $category = IncidentCategory::tryFrom((string) ($payload['category'] ?? ''));
        $severity = isset($payload['severity']) ? Severity::tryFrom((string) $payload['severity']) : null;
        $description = trim((string) ($payload['description'] ?? ''));

        if ($category === null || (isset($payload['severity']) && $severity === null) || $description === '' || mb_strlen($description) > 1000) {
            return null;
        }

        $clientRef = isset($payload['client_ref']) ? trim((string) $payload['client_ref']) : null;

        if ($clientRef !== null && mb_strlen($clientRef) > 120) {
            return null;
        }

        return array_filter([
            'category' => $category->value,
            'severity' => $severity?->value,
            'description' => $description,
            'client_ref' => $clientRef !== '' ? $clientRef : null,
            'department_id' => is_numeric($payload['department_id'] ?? null) ? (int) $payload['department_id'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function task(array $payload): ?array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $description = isset($payload['description']) ? trim((string) $payload['description']) : null;
        $due = $payload['due_in_hours'] ?? null;

        if (mb_strlen($title) < 3 || mb_strlen($title) > 160) {
            return null;
        }

        if ($description !== null && mb_strlen($description) > 1000) {
            return null;
        }

        if ($due !== null && (! is_numeric($due) || (int) $due < 1 || (int) $due > 720)) {
            return null;
        }

        return array_filter([
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'due_in_hours' => $due !== null ? (int) $due : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function escalate(array $payload): ?array
    {
        $reason = QuestionReason::tryFrom((string) ($payload['reason'] ?? 'user_request'));

        return $reason !== null ? ['reason' => $reason->value] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function openSource(array $payload): ?array
    {
        if (! is_numeric($payload['document_id'] ?? null) || (int) $payload['document_id'] < 1) {
            return null;
        }

        return array_filter([
            'document_id' => (int) $payload['document_id'],
            'chunk_id' => is_numeric($payload['chunk_id'] ?? null) ? (int) $payload['chunk_id'] : null,
            'page' => is_numeric($payload['page'] ?? null) ? (int) $payload['page'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function ask(array $payload): ?array
    {
        $question = trim((string) ($payload['question'] ?? ''));

        return $question !== '' && mb_strlen($question) <= 200 ? ['question' => Str::limit($question, 200, '')] : null;
    }
}
