<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Presenters;

use App\Enums\ApprovalStatus;
use App\Models\Department;
use App\Models\Document;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DocumentPresenter
{
    /**
     * @param  Collection<int, Document>  $documents
     * @return array{departments: Collection<int, Department>, successors: Collection<int, Document>, supersedes: Collection<int, Document>, cited: array<int, int>}
     */
    public static function context(Collection $documents, int $workspaceId): array
    {
        $departmentIds = $documents->flatMap(fn (Document $document): array => $document->department_ids)->unique()->values()->all();
        $ids = $documents->pluck('id')->all();
        $supersedeIds = $documents->pluck('supersedes_document_id')->filter()->unique()->values()->all();

        $cited = [];

        if ($ids !== []) {
            $rows = DB::select(
                "SELECT (v->>'document_id')::bigint AS document_id, count(*) AS total
                 FROM ai_queries q CROSS JOIN LATERAL jsonb_array_elements(q.cited_document_versions) AS v
                 WHERE q.workspace_id = ? AND q.created_at >= ? AND (v->>'document_id')::bigint = ANY(?::bigint[])
                 GROUP BY 1",
                [$workspaceId, now()->subDays(30), '{'.implode(',', $ids).'}'],
            );

            foreach ($rows as $row) {
                $cited[(int) $row->document_id] = (int) $row->total;
            }
        }

        return [
            'departments' => Department::query()->forWorkspace($workspaceId)->whereIn('id', $departmentIds)->get()->keyBy('id'),
            'successors' => Document::query()
                ->forWorkspace($workspaceId)
                ->whereIn('supersedes_document_id', $ids === [] ? [0] : $ids)
                ->where('approval_status', '!=', ApprovalStatus::Archived->value)
                ->orderByDesc('id')
                ->get()
                ->keyBy('supersedes_document_id'),
            'supersedes' => Document::query()->forWorkspace($workspaceId)->whereIn('id', $supersedeIds === [] ? [0] : $supersedeIds)->get()->keyBy('id'),
            'cited' => $cited,
        ];
    }

    /**
     * @param  array{departments: Collection<int, Department>, successors: Collection<int, Document>, supersedes: Collection<int, Document>, cited: array<int, int>}  $context
     * @return array<string, mixed>
     */
    public static function present(Document $document, array $context, string $today): array
    {
        $supersedes = $document->supersedes_document_id !== null ? $context['supersedes']->get($document->supersedes_document_id) : null;
        $successor = $context['successors']->get($document->id);
        $departments = [];

        foreach ($document->department_ids as $id) {
            $department = $context['departments']->get($id);

            if ($department !== null) {
                $departments[] = $department->toRef();
            }
        }

        return [
            'id' => $document->id,
            'title' => $document->title,
            'original_name' => $document->original_name,
            'mime' => $document->mime,
            'size_bytes' => $document->size_bytes,
            'department_ids' => $document->department_ids,
            'departments' => $departments,
            'roles' => $document->roles,
            'tags' => $document->tags,
            'version' => $document->version,
            'language' => in_array($document->language, ['ru', 'en'], true) ? $document->language : null,
            'status' => $document->status->value,
            'pages' => $document->pages,
            'pages_done' => $document->pages_done,
            'pages_total' => $document->pages_total,
            'chunks' => $document->chunks,
            'ocr_used' => $document->ocr_used,
            'error' => $document->error,
            'error_code' => $document->error_code,
            'approval_status' => $document->approval_status->value,
            'approved_by' => Refs::user($document->approver),
            'approved_at' => $document->approved_at?->toIso8601String(),
            'effective_date' => $document->effective_date?->toDateString(),
            'review_due_at' => $document->review_due_at?->toDateString(),
            'review_overdue' => $document->approval_status === ApprovalStatus::Approved
                && $document->review_due_at !== null
                && $document->review_due_at->toDateString() <= $today,
            'supersedes' => $supersedes !== null
                ? ['id' => $supersedes->id, 'title' => $supersedes->title, 'version' => $supersedes->version]
                : null,
            'superseded_by' => $successor !== null
                ? ['id' => $successor->id, 'title' => $successor->title, 'version' => $successor->version, 'effective_date' => $successor->effective_date?->toDateString()]
                : null,
            'archived_at' => $document->archived_at?->toIso8601String(),
            'archive_reason' => $document->archive_reason?->value,
            'source' => $document->source->value,
            'cited_30d' => $context['cited'][$document->id] ?? 0,
            'uploaded_by' => Refs::user($document->uploader),
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }
}
