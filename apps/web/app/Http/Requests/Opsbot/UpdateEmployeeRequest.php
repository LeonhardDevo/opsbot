<?php

declare(strict_types=1);

namespace App\Http\Requests\Opsbot;

use App\Enums\WorkspaceRole;
use App\Locale\Locales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'department_ids' => ['sometimes', 'array', 'max:20'],
            'department_ids.*' => ['integer', 'distinct'],
            'primary_department_id' => ['sometimes', 'nullable', 'integer'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s()\-.]{7,24}$/'],
            'position' => ['sometimes', 'nullable', 'string', 'max:120'],
            'locale' => ['sometimes', 'string', Rule::in(Locales::supported())],
            'role' => ['sometimes', 'string', Rule::enum(WorkspaceRole::class)],
        ];
    }

    /** @return array{name?: string, department_ids?: list<int>, primary_department_id?: int|null, phone?: string|null, position?: string|null, locale?: string, role?: string} */
    public function payload(): array
    {
        $data = $this->validated();
        $payload = [];

        foreach (['name', 'locale', 'role'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = (string) $data[$key];
            }
        }

        foreach (['phone', 'position'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $data[$key] !== null ? (string) $data[$key] : null;
            }
        }

        if (array_key_exists('department_ids', $data)) {
            $payload['department_ids'] = array_values(array_map('intval', (array) $data['department_ids']));
        }

        if (array_key_exists('primary_department_id', $data)) {
            $payload['primary_department_id'] = $data['primary_department_id'] !== null ? (int) $data['primary_department_id'] : null;
        }

        return $payload;
    }
}
