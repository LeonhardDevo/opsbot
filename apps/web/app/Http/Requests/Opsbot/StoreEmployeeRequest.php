<?php

declare(strict_types=1);

namespace App\Http\Requests\Opsbot;

use App\Locale\Locales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'department_ids' => ['present', 'array', 'max:20'],
            'department_ids.*' => ['integer', 'distinct'],
            'primary_department_id' => ['nullable', 'integer'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s()\-.]{7,24}$/'],
            'position' => ['nullable', 'string', 'max:120'],
            'locale' => ['required', 'string', Rule::in(Locales::supported())],
        ];
    }

    /** @return array{name: string, department_ids: list<int>, primary_department_id: int|null, phone: string|null, position: string|null, locale: string} */
    public function payload(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'department_ids' => array_values(array_map('intval', (array) $this->validated('department_ids', []))),
            'primary_department_id' => $this->validated('primary_department_id') !== null ? (int) $this->validated('primary_department_id') : null,
            'phone' => $this->validated('phone'),
            'position' => $this->validated('position'),
            'locale' => (string) $this->validated('locale'),
        ];
    }
}
