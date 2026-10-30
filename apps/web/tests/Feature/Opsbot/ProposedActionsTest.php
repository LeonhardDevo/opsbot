<?php

declare(strict_types=1);

use App\Enums\ActionStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiQuery;
use App\Models\AiQueryAction;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Incident;
use App\Models\Task;
use App\Models\UnansweredQuestion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\RateLimiter;

/** @return array<string, mixed> */
function loggedQuery(Workspace $workspace, User $user, array $overrides = []): array
{
    return [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'channel' => 'telegram',
        'bot_key' => 'platform',
        'conversation_id' => 'tg-platform-900',
        'question' => 'I scratched a client\'s table, what do I do?',
        'answer' => 'Stop work and take photos [1].',
        'refused' => false,
        'confidence' => 0.83,
        'answer_kind' => 'document',
        'role_at_time' => 'employee',
        'department_ids_at_time' => [],
        'citations' => [['document_id' => 0, 'chunk_id' => 12, 'page' => 1, 'version' => '1.0', 'title' => 'Damage']],
        'tools_used' => ['search_documents'],
        'disclaimers' => [],
        'latency_ms' => 900,
        'provider' => 'mock',
        'model' => 'mock-1',
        'actions' => [
            ['type' => 'open_source', 'label' => 'Open source', 'payload' => ['document_id' => 0, 'chunk_id' => 12, 'page' => 1]],
            ['type' => 'create_incident', 'label' => 'Create incident', 'payload' => ['category' => 'damage', 'description' => 'I scratched a client\'s table']],
            ['type' => 'create_task', 'label' => 'Bad task', 'payload' => ['title' => 'x']],
            ['type' => 'delete_everything', 'payload' => []],
        ],
        ...$overrides,
    ];
}

it('logs the query with versions, validated actions and signed citation links', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    $document = Document::factory()->approved()->create(['workspace_id' => $workspace->id, 'title' => 'Damage', 'version' => '1.0']);
    $payload = loggedQuery($workspace, $employee);
    $payload['citations'][0]['document_id'] = $document->id;
    $payload['actions'][0]['payload']['document_id'] = $document->id;

    $response = $this->internal('POST', '/internal/v1/ai-queries', $payload)->assertCreated();
    $query = AiQuery::query()->sole();

    expect($query->bot_key)->toBe('platform')
        ->and($query->role_at_time)->toBe('employee')
        ->and($query->cited_document_versions)->toEqual([[
            'document_id' => $document->id, 'title' => 'Damage', 'version' => '1.0', 'approval_status' => 'approved', 'effective_date' => '2026-01-01',
        ]])
        ->and(AiQueryAction::query()->count())->toBe(2)
        ->and($response->json('actions.0.type'))->toBe('create_incident')
        ->and($response->json('actions.0.url'))->toBeNull()
        ->and($response->json('actions.0.status'))->toBe('proposed')
        ->and($response->json('actions.1.type'))->toBe('open_source')
        ->and($response->json('actions.1.url'))->toContain('/s/doc/'.$document->id)->toContain('signature=')->toContain('u='.$employee->id)
        ->and($response->json('citations.0.index'))->toBe(1)
        ->and($response->json('citations.0.url'))->toContain('c=12');
});

it('always proposes an escalation for refused answers and keeps at most four actions', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);

    $this->internal('POST', '/internal/v1/ai-queries', loggedQuery($workspace, $employee, [
        'refused' => true,
        'refusal_reason' => 'not_found',
        'answer' => null,
        'citations' => [],
        'actions' => [],
    ]))->assertCreated()->assertJsonPath('actions.0.type', 'escalate');

    expect(AiQueryAction::query()->sole()->payload)->toBe(['reason' => 'not_found']);

    $five = array_fill(0, 5, ['type' => 'ask', 'payload' => ['question' => 'More?']]);
    $this->internal('POST', '/internal/v1/ai-queries', loggedQuery($workspace, $employee, ['actions' => $five, 'citations' => []]), 'ai');

    expect(AiQueryAction::query()->count())->toBe(5);
});

it('ignores limit_reached escalations unless the query was refused for the limit', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);

    $this->internal('POST', '/internal/v1/ai-queries', loggedQuery($workspace, $employee, [
        'citations' => [],
        'actions' => [['type' => 'escalate', 'payload' => ['reason' => 'limit_reached']]],
    ]))->assertCreated()->assertJsonPath('actions', []);

    $this->internal('POST', '/internal/v1/ai-queries', loggedQuery($workspace, $employee, [
        'refused' => true,
        'refusal_reason' => 'limit_reached',
        'citations' => [],
        'actions' => [['type' => 'escalate', 'payload' => ['reason' => 'limit_reached']]],
    ]))->assertCreated()->assertJsonPath('actions.0.type', 'escalate');
});

it('previews and confirms an incident proposal exactly once', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee, null, ['locale' => 'en']);
    [$owner] = $this->member(WorkspaceRole::Owner, $workspace);
    $this->linkTelegram($employee, $workspace, 'platform', 900);
    $actionId = $this->internal('POST', '/internal/v1/ai-queries', loggedQuery($workspace, $employee, ['citations' => []]))->json('actions.0.id');

    $call = fn (string $step) => $this->internal('POST', '/internal/v1/telegram/actions', [
        'bot_key' => 'platform', 'type' => 'opsbot.action', 'payload' => ['action_id' => $actionId, 'step' => $step],
        'telegram_user_id' => 900, 'chat_id' => 900,
    ]);

    $preview = $call('preview')->assertOk();
    expect($preview->json('message_html'))->toContain('<b>New incident</b>')->toContain('I scratched a client&#039;s table')
        ->and($preview->json('buttons'))->toBe([
            ['text' => 'Confirm', 'callback' => 'opsbot.action:'.$actionId.':confirm'],
            ['text' => 'Cancel', 'callback' => 'opsbot.action:'.$actionId.':cancel'],
        ])
        ->and(Incident::query()->count())->toBe(0);

    $call('confirm')->assertOk()->assertJson(['ok' => true, 'message' => 'Incident #1 created. Responsible: '.$owner->name.'.']);
    $call('confirm')->assertOk()->assertJson(['ok' => true, 'message' => 'Incident #1 has already been created. Responsible: '.$owner->name.'.']);

    $incident = Incident::query()->sole();
    $action = AiQueryAction::query()->findOrFail($actionId);
    expect($incident->source->value)->toBe('ai_proposal')
        ->and($incident->ai_query_action_id)->toBe($actionId)
        ->and($action->status)->toBe(ActionStatus::Executed)
        ->and($action->result_type)->toBe('incident')
        ->and($action->result_id)->toBe($incident->id)
        ->and(AuditLog::query()->where('action', 'incident.created')->sole()->metadata['source'])->toBe('ai_proposal');
});

it('accepts the callback argument format of stored buttons', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    $this->member(WorkspaceRole::Owner, $workspace);
    $this->linkTelegram($employee, $workspace, 'platform', 901);
    $actionId = $this->internal('POST', '/internal/v1/ai-queries', loggedQuery($workspace, $employee, ['citations' => []]))->json('actions.0.id');

    $this->internal('POST', '/internal/v1/telegram/actions', [
        'bot_key' => 'platform', 'type' => 'opsbot.action', 'payload' => ['value' => 'opsbot.action:'.$actionId.':cancel', 'arg' => $actionId.':cancel'],
        'telegram_user_id' => 901, 'chat_id' => 901,
    ])->assertOk()->assertJson(['ok' => true]);

    expect(AiQueryAction::query()->findOrFail($actionId)->status)->toBe(ActionStatus::Cancelled);
});

it('rejects expired proposals and taps by another user', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee, null, ['locale' => 'ru']);
    [$colleague] = $this->member(WorkspaceRole::Employee, $workspace, ['locale' => 'ru']);
    $this->linkTelegram($employee, $workspace, 'platform', 902);
    $this->linkTelegram($colleague, $workspace, 'platform', 903);
    $actionId = $this->internal('POST', '/internal/v1/ai-queries', loggedQuery($workspace, $employee, ['citations' => []]))->json('actions.0.id');

    $this->internal('POST', '/internal/v1/telegram/actions', [
        'bot_key' => 'platform', 'type' => 'opsbot.action', 'payload' => ['action_id' => $actionId, 'step' => 'confirm'],
        'telegram_user_id' => 903, 'chat_id' => 903,
    ])->assertOk()->assertJson(['ok' => false, 'message' => __('opsbot.actions_result.foreign', [], 'ru')]);

    $this->travel(25)->hours();

    $this->internal('POST', '/internal/v1/telegram/actions', [
        'bot_key' => 'platform', 'type' => 'opsbot.action', 'payload' => ['action_id' => $actionId, 'step' => 'confirm'],
        'telegram_user_id' => 902, 'chat_id' => 902,
    ])->assertOk()->assertJson(['ok' => false, 'message' => __('opsbot.actions_result.expired', [], 'ru')]);

    expect(AiQueryAction::query()->findOrFail($actionId)->status)->toBe(ActionStatus::Expired)
        ->and(Incident::query()->count())->toBe(0);
});

it('creates tasks from proposals with the routing assignee and limits executions per hour', function (): void {
    [$employee, $workspace] = $this->signInMember(WorkspaceRole::Employee);
    [$owner] = $this->member(WorkspaceRole::Owner, $workspace);
    RateLimiter::clear('opsbot-actions:'.$employee->id);

    $ids = [];

    for ($i = 0; $i < 6; $i++) {
        $query = $this->aiQuery($workspace, $employee, ['refused' => false]);
        $ids[] = AiQueryAction::query()->create([
            'workspace_id' => $workspace->id, 'ai_query_id' => $query->id, 'user_id' => $employee->id, 'type' => 'create_task',
            'label' => 'Create task', 'payload' => ['title' => 'Buy gloves '.$i, 'due_in_hours' => 24], 'status' => 'proposed', 'expires_at' => now()->addDay(),
        ])->id;
    }

    $this->postJson(route('assistant.actions', $ids[0]), ['step' => 'preview'])
        ->assertOk()
        ->assertJsonPath('card.title', __('opsbot.card.task_title'))
        ->assertJsonPath('card.fields.1.value', 'in 24 hours');

    foreach (array_slice($ids, 0, 5) as $id) {
        $this->postJson(route('assistant.actions', $id), ['step' => 'confirm'])->assertOk()->assertJsonPath('result.type', 'task');
    }

    $this->postJson(route('assistant.actions', $ids[5]), ['step' => 'confirm'])
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'message' => __('opsbot.actions_result.rate_limited')]);

    $task = Task::query()->orderBy('id')->firstOrFail();
    expect(Task::query()->count())->toBe(5)
        ->and($task->assignee_id)->toBe($owner->id)
        ->and($task->source->value)->toBe('ai_proposal')
        ->and($task->due_at?->isFuture())->toBeTrue();
});

it('escalates in one tap from the web assistant', function (): void {
    [$employee, $workspace] = $this->signInMember(WorkspaceRole::Employee);
    $this->member(WorkspaceRole::Owner, $workspace);
    $query = $this->aiQuery($workspace, $employee);
    $action = AiQueryAction::query()->create([
        'workspace_id' => $workspace->id, 'ai_query_id' => $query->id, 'user_id' => $employee->id, 'type' => 'escalate',
        'label' => 'Pass to manager', 'payload' => ['reason' => 'not_found'], 'status' => 'proposed', 'expires_at' => now()->addDay(),
    ]);

    $this->postJson(route('assistant.actions', $action->id), ['step' => 'preview'])
        ->assertOk()
        ->assertJsonPath('result.type', 'question')
        ->assertJsonPath('result.display_number', 'Q-1');

    expect(UnansweredQuestion::query()->sole()->ai_query_id)->toBe($query->id);

    [, $other] = $this->member(WorkspaceRole::Owner);
    $this->postJson(route('assistant.actions', '00000000-0000-0000-0000-000000000000'), ['step' => 'confirm'])->assertStatus(422);
    expect($other->exists)->toBeTrue();
});

it('signs citation links on the public app url even when the internal call arrives on another host', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    $document = Document::factory()->approved()->create(['workspace_id' => $workspace->id]);
    $payload = loggedQuery($workspace, $employee);
    $payload['citations'][0]['document_id'] = $document->id;
    $payload['actions'][0]['payload']['document_id'] = $document->id;
    config(['app.url' => 'https://ops.example.test']);

    $response = $this->internal('POST', '/internal/v1/ai-queries', $payload)->assertCreated();

    expect($response->json('citations.0.url'))->toStartWith('https://ops.example.test/s/doc/'.$document->id.'?');
});
