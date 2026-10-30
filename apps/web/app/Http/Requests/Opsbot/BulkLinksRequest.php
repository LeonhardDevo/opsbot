<?php

declare(strict_types=1);

namespace App\Http\Requests\Opsbot;

use Illuminate\Foundation\Http\FormRequest;

final class BulkLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:'.(int) config('opsbot.employee_links.bulk_max', 50)],
            'user_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function userIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('user_ids')));
    }
}
