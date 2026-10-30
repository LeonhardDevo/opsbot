<?php

declare(strict_types=1);

namespace App\Http\Requests\Knowledge;

use App\Enums\WorkspaceRole;
use Illuminate\Validation\Rule;

trait DocumentMetadataRules
{
    /** @return array<string, array<int, mixed>> */
    protected function metadataRules(bool $titleRequired): array
    {
        return [
            'title' => [$titleRequired ? 'required' : 'nullable', 'string', 'max:250'],
            'department_ids' => ['nullable', 'array', 'max:50'],
            'department_ids.*' => ['integer', 'distinct'],
            'roles' => ['nullable', 'array', 'max:4'],
            'roles.*' => ['string', 'distinct', Rule::in(WorkspaceRole::values())],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'distinct', 'max:40'],
            'version' => ['nullable', 'string', 'max:40'],
            'language' => ['nullable', 'string', Rule::in(['ru', 'en'])],
        ];
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $data = $this->safe()->only(['title', 'department_ids', 'roles', 'tags', 'version', 'language', 'review_due_at', 'effective_date', 'supersedes_document_id']);

        foreach (['roles', 'tags'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = array_values(array_filter(
                    array_map(fn (mixed $value): string => trim((string) $value), (array) ($data[$list] ?? [])),
                    fn (string $value): bool => $value !== '',
                ));
            }
        }

        if (array_key_exists('department_ids', $data)) {
            $ids = array_values(array_unique(array_map('intval', (array) ($data['department_ids'] ?? []))));
            sort($ids);
            $data['department_ids'] = $ids;
        }

        foreach (['version', 'language', 'title'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]) === '' ? null : trim($data[$field]);
            }
        }

        if (array_key_exists('supersedes_document_id', $data)) {
            $data['supersedes_document_id'] = $data['supersedes_document_id'] !== null ? (int) $data['supersedes_document_id'] : null;
        }

        return $data;
    }
}
