<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Enums\AiChannel;
use App\Enums\AnswerKind;
use App\Enums\RefusalReason;
use App\Enums\WorkspaceRole;
use App\Models\TelegramBot;
use Illuminate\Validation\Rule;

final class AiQueryRequest extends InternalRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'channel' => ['required', 'string', Rule::in(AiChannel::values())],
            'question' => ['required', 'string', 'max:10000'],
            'answer' => ['nullable', 'string', 'max:100000'],
            'refused' => ['required', 'boolean'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'citations' => ['present', 'array', 'max:20'],
            'citations.*.document_id' => ['required', 'integer'],
            'citations.*.chunk_id' => ['nullable', 'integer'],
            'citations.*.title' => ['required', 'string', 'max:500'],
            'citations.*.page' => ['nullable', 'integer'],
            'citations.*.version' => ['nullable', 'string', 'max:40'],
            'citations.*.snippet' => ['nullable', 'string', 'max:2000'],
            'tools_used' => ['present', 'array'],
            'tools_used.*' => ['string', 'max:120'],
            'latency_ms' => ['nullable', 'integer', 'min:0'],
            'provider' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:160'],
            'answer_kind' => ['nullable', 'string', Rule::in(AnswerKind::values())],
            'bot_key' => ['nullable', 'string', 'regex:'.TelegramBot::KEY_PATTERN],
            'conversation_id' => ['nullable', 'string', 'max:120'],
            'role_at_time' => ['nullable', 'string', Rule::in(WorkspaceRole::values())],
            'department_ids_at_time' => ['nullable', 'array', 'max:100'],
            'department_ids_at_time.*' => ['integer'],
            'refusal_reason' => ['nullable', 'string', Rule::in(RefusalReason::values())],
            'disclaimers' => ['nullable', 'array', 'max:10'],
            'disclaimers.*' => ['string', 'max:40'],
            'actions' => ['nullable', 'array', 'max:10'],
            'actions.*.type' => ['required', 'string', 'max:24'],
            'actions.*.label' => ['nullable', 'string', 'max:120'],
            'actions.*.payload' => ['nullable', 'array'],
        ];
    }
}
