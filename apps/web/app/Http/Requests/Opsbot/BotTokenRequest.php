<?php

declare(strict_types=1);

namespace App\Http\Requests\Opsbot;

use Illuminate\Foundation\Http\FormRequest;

final class BotTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bot.manage') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:100', 'regex:/^\d{5,16}:[A-Za-z0-9_-]{30,64}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('token'))) {
            $this->merge(['token' => trim($this->input('token'))]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'token.regex' => (string) __('errors.bot.invalid_token'),
        ];
    }
}
