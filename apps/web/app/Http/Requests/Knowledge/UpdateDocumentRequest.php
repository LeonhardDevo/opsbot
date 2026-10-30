<?php

declare(strict_types=1);

namespace App\Http\Requests\Knowledge;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateDocumentRequest extends FormRequest
{
    use DocumentMetadataRules;

    public function authorize(): bool
    {
        return $this->user()?->can('knowledge.manage') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = $this->metadataRules(titleRequired: true);
        $rules['title'] = ['sometimes', ...$rules['title']];
        $rules['review_due_at'] = ['sometimes', 'nullable', 'date_format:Y-m-d'];
        $rules['effective_date'] = ['sometimes', 'nullable', 'date_format:Y-m-d'];

        return $rules;
    }
}
