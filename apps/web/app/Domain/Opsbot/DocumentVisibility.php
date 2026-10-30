<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Casts\PgIntegerArray;
use App\Domain\Opsbot\Access\Actor;
use App\Enums\ApprovalStatus;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;

final class DocumentVisibility
{
    /**
     * @param  list<int>  $documentDepartmentIds
     * @param  list<string>  $documentRoles
     * @param  list<int>  $userDepartmentIds
     */
    public static function visible(
        array $documentDepartmentIds,
        array $documentRoles,
        bool $privileged,
        string $role,
        array $userDepartmentIds,
    ): bool {
        if ($privileged) {
            return true;
        }

        $departmentOk = $documentDepartmentIds === [] || array_intersect($documentDepartmentIds, $userDepartmentIds) !== [];
        $roleOk = $documentRoles === [] || in_array($role, $documentRoles, true);

        return $departmentOk && $roleOk;
    }

    public function visibleTo(Document $document, Actor $actor): bool
    {
        return $document->workspace_id === $actor->workspaceId() && self::visible(
            $document->department_ids,
            $document->roles,
            $actor->can('knowledge.view_all'),
            $actor->role->value,
            $actor->departmentIds,
        );
    }

    public function answerable(Document $document, Actor $actor, string $today): bool
    {
        return $this->visibleTo($document, $actor)
            && $document->approval_status === ApprovalStatus::Approved
            && $document->archived_at === null
            && $document->effective_date !== null
            && $document->effective_date->toDateString() <= $today;
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scope(Builder $query, Actor $actor): Builder
    {
        $query->where('documents.workspace_id', $actor->workspaceId());

        if ($actor->can('knowledge.view_all')) {
            return $query;
        }

        return $query
            ->where(function (Builder $inner) use ($actor): void {
                $inner->whereRaw('cardinality(documents.department_ids) = 0');

                if ($actor->departmentIds !== []) {
                    $inner->orWhereRaw('documents.department_ids && ?::integer[]', [PgIntegerArray::format($actor->departmentIds)]);
                }
            })
            ->where(function (Builder $inner) use ($actor): void {
                $inner->whereRaw('jsonb_array_length(documents.roles) = 0')
                    ->orWhereRaw('documents.roles @> ?::jsonb', [json_encode([$actor->role->value], JSON_THROW_ON_ERROR)]);
            });
    }
}
