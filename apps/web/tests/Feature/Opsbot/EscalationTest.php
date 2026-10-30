<?php

declare(strict_types=1);

use App\Domain\Opsbot\EscalationService;
use App\Domain\Opsbot\OpsbotException;
use App\Enums\DismissReason;
use App\Enums\QuestionReason;
use App\Enums\QuestionStatus;
use App\Enums\RefusalReason;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiQuery;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\OutboxMessage;
use App\Models\RoutingRule;
use App\Models\Subscription;
use App\Models\UnansweredQuestion;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('documents');
});

it('escalates a query once, routes it and notifies the assignee', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    [$manager] = $this->member(WorkspaceRole::Manager, $workspace, ['locale' => 'ru']);
    $department = $this->department($workspace, 'residential');
    $this->inDepartment($employee, $department);
    RoutingRule::query()->create(['workspace_id' => $workspace->id, 'department_id' => $department->id, 'category' => 'question', 'manager_user_id' => $manager->id]);
    $query = $this->aiQuery($workspace, $employee, ['refusal_reason' => RefusalReason::LowConfidence]);

    $service = app(EscalationService::class);
    $actor = $this->actorFor($employee, $workspace);
    $first = $service->escalate($actor, $query);
    $second = $service->escalate($actor, $query);

    expect($second->id)->toBe($first->id)
        ->and(UnansweredQuestion::query()->count())->toBe(1)
        ->and($first->reason)->toBe(QuestionReason::LowConfidence)
        ->and($first->department_id)->toBe($department->id)
        ->and($first->assignee_user_id)->toBe($manager->id)
        ->and($query->refresh()->escalated_question_id)->toBe($first->id)
        ->and(AuditLog::query()->where('action', 'question.escalated')->count())->toBe(1);

    $message = OutboxMessage::query()->topic('opsbot.question.escalated')->sole();
    expect($message->payload['question'])->toMatchArray([
        'display_number' => 'Q-1',
        'employee_name' => $employee->name,
        'department_name' => 'Residential',
        'reason' => 'low_confidence',
    ])->and($message->payload['web_url'])->toEndWith('/unanswered?focus='.$first->id);
});

it('refuses to escalate somebody else\'s query', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    [$other] = $this->member(WorkspaceRole::Employee, $workspace);
    $query = $this->aiQuery($workspace, $other);

    expect(fn () => app(EscalationService::class)->escalate($this->actorFor($employee, $workspace), $query))
        ->toThrow(OpsbotException::class);
});

it('answers in the web and relays the labelled answer to the employee', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace, ['locale' => 'ru']);
    $department = $this->department($workspace, 'mine');
    $this->inDepartment($manager, $department);
    $query = $this->aiQuery($workspace, $employee);
    $question = $this->question($workspace, $employee, ['department_id' => $department->id, 'ai_query_id' => $query->id, 'question' => 'Можно ли <b>такси</b>?']);

    $this->post(route('unanswered.answer', $question), ['answer_text' => "Да, <до> 500 ₽\nс чеком"])->assertSessionHas('success');

    expect($question->refresh()->status)->toBe(QuestionStatus::Answered)
        ->and($question->answered_by)->toBe($manager->id);

    $message = OutboxMessage::query()->topic('opsbot.question.answered')->sole();
    expect($message->payload)->toMatchArray([
        'query_id' => $query->id,
        'question_id' => $question->id,
        'question_excerpt' => 'Можно ли <b>такси</b>?',
        'text_html' => "Да, &lt;до&gt; 500 ₽<br>\nс чеком",
        'manager_name' => $manager->name,
    ])->and($message->payload['recipient'])->toEqual(['user_id' => $employee->id, 'locale' => 'ru']);

    $this->post(route('unanswered.answer', $question), ['answer_text' => 'Again'])->assertSessionHas('error', __('errors.questions.not_open'));
});

it('forbids answering outside the manager departments', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $other = $this->department($workspace, 'other');
    $question = $this->question($workspace, $employee, ['department_id' => $other->id]);

    $this->post(route('unanswered.answer', $question), ['answer_text' => 'Nope'])->assertSessionHas('error');
    expect($question->refresh()->status)->toBe(QuestionStatus::Open)
        ->and($manager->exists)->toBeTrue();
});

it('dismisses with a required reason and converts answered questions into FAQ drafts', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $toDismiss = $this->question($workspace, $employee);
    $answered = $this->question($workspace, $employee, ['status' => QuestionStatus::Answered, 'answer_text' => 'Ask the office admin.', 'answered_by' => $owner->id, 'answered_at' => now()]);

    $this->post(route('unanswered.dismiss', $toDismiss), [])->assertSessionHasErrors('reason');
    $this->post(route('unanswered.dismiss', $toDismiss), ['reason' => 'out_of_scope'])->assertSessionHas('success');
    expect($toDismiss->refresh()->status)->toBe(QuestionStatus::Dismissed)
        ->and($toDismiss->dismissed_reason)->toBe(DismissReason::OutOfScope);

    $this->post(route('unanswered.convert', $toDismiss))->assertSessionHas('error');

    $response = $this->post(route('unanswered.convert', $answered), ['title' => 'Guest Wi-Fi']);
    $document = Document::query()->sole();
    $response->assertRedirect(route('knowledge.show', $document));

    expect($answered->refresh()->status)->toBe(QuestionStatus::ConvertedToDoc)
        ->and($answered->document_id)->toBe($document->id)
        ->and($document->title)->toBe('Guest Wi-Fi')
        ->and($document->approval_status->value)->toBe('draft')
        ->and($document->source->value)->toBe('faq')
        ->and(Storage::disk('documents')->get($document->path))->toContain('Ask the office admin.')
        ->and(OutboxMessage::query()->topic('document.uploaded')->count())->toBe(1)
        ->and(AuditLog::query()->whereIn('action', ['question.dismissed', 'question.converted_to_doc'])->count())->toBe(2);
});

it('lists the queue with groups of similar questions, counts and focus', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $a = $this->question($workspace, $employee, ['question' => 'What is the guest Wi-Fi password?']);
    $b = $this->question($workspace, $employee, ['question' => 'what is the guest wifi password']);
    $old = $this->question($workspace, $employee, ['question' => 'Vacation days per year?']);
    $old->forceFill(['created_at' => now()->subHours(30)])->save();
    $this->question($workspace, $employee, ['status' => QuestionStatus::Answered]);

    $this->get(route('unanswered.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('unanswered/Index', false)
            ->has('questions.data', 3)
            ->where('questions.data.0.id', $old->id)
            ->where('questions.data.0.age_hours', 30)
            ->where('counts', ['open' => 3, 'answered' => 1, 'converted_to_doc' => 0, 'dismissed' => 0, 'overdue_24h' => 1])
            ->where('groups', [['representative' => 'What is the guest Wi-Fi password?', 'count' => 2, 'question_ids' => [$a->id, $b->id]]])
            ->where('filters.status', 'open'));

    $this->get(route('unanswered.index', ['status' => 'all']))->assertInertia(fn ($page) => $page->has('questions.data', 4));
    expect($owner->exists)->toBeTrue();
});

it('reassigns questions only to people who can answer them', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    [$manager] = $this->member(WorkspaceRole::Manager, $workspace);
    $question = $this->question($workspace, $employee);

    $this->patch(route('unanswered.update', $question), ['assignee_user_id' => $employee->id])->assertSessionHas('error');
    $this->patch(route('unanswered.update', $question), ['assignee_user_id' => $manager->id])->assertSessionHas('success');

    expect($question->refresh()->assignee_user_id)->toBe($manager->id)
        ->and(OutboxMessage::query()->topic('opsbot.question.escalated')->sole()->payload['recipient']['user_id'])->toBe($manager->id);
});

it('escalates the last question from telegram and refuses while paused', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee, null, ['locale' => 'en']);
    [$owner] = $this->member(WorkspaceRole::Owner, $workspace);
    $this->linkTelegram($employee, $workspace, 'platform', 41);

    $this->internal('POST', '/internal/v1/opsbot/questions/escalate-last', ['bot_key' => 'platform', 'telegram_user_id' => 41])
        ->assertOk()->assertJson(['ok' => false]);

    $this->aiQuery($workspace, $employee, ['bot_key' => 'platform', 'refused' => false, 'answer' => 'ok']);
    $this->internal('POST', '/internal/v1/opsbot/questions/escalate-last', ['bot_key' => 'platform', 'telegram_user_id' => 41])
        ->assertOk()
        ->assertJson(['ok' => true, 'message_html' => 'Your question <b>Q-1</b> was passed to <b>'.e($owner->name).'</b>.']);

    expect(UnansweredQuestion::query()->sole()->reason)->toBe(QuestionReason::UserRequest);

    Subscription::query()->create(['workspace_id' => $workspace->id, 'plan' => 'pro', 'status' => SubscriptionStatus::PastDue, 'past_due_since' => now()->subDays(8)]);
    $this->internal('POST', '/internal/v1/opsbot/questions/escalate-last', ['bot_key' => 'platform', 'telegram_user_id' => 41])
        ->assertOk()->assertJson(['ok' => false, 'message_html' => e(__('opsbot.subscription.paused', [], 'en'))]);
});

it('accepts force-reply answers from managers and offers the FAQ conversion', function (): void {
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager, null, ['locale' => 'en']);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $this->linkTelegram($manager, $workspace, 'platform', 51);
    $question = $this->question($workspace, $employee, ['assignee_user_id' => $manager->id]);

    $this->internal('POST', '/internal/v1/opsbot/questions/'.$question->id.'/answer', ['bot_key' => 'platform', 'telegram_user_id' => 51, 'text' => 'Ask Daria at the office.'])
        ->assertOk()
        ->assertExactJson([
            'ok' => true,
            'message_html' => 'Your answer to <b>Q-1</b> was sent to the employee.',
            'buttons' => [['text' => 'Save as FAQ draft', 'callback' => 'q_convert:'.$question->id]],
        ]);

    expect($question->refresh()->status)->toBe(QuestionStatus::Answered)
        ->and(AuditLog::query()->where('action', 'question.answered')->sole()->user_agent)->toBe('telegram');
});

it('lists the telegram queue for managers and refuses it for employees', function (): void {
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $this->linkTelegram($manager, $workspace, 'platform', 61);
    $this->linkTelegram($employee, $workspace, 'platform', 62);
    $question = $this->question($workspace, $employee, ['assignee_user_id' => $manager->id]);

    $this->internal('GET', '/internal/v1/opsbot/queue?bot_key=platform&telegram_user_id=61')
        ->assertOk()
        ->assertJsonPath('items.0.id', $question->id)
        ->assertJsonPath('items.0.display_number', 'Q-1')
        ->assertJsonPath('items.0.employee_name', $employee->name);

    $this->internal('GET', '/internal/v1/opsbot/queue?bot_key=platform&telegram_user_id=62')->assertForbidden();
});

it('dismisses from telegram with reason buttons first', function (): void {
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager, null, ['locale' => 'en']);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $this->linkTelegram($manager, $workspace, 'platform', 71);
    $question = $this->question($workspace, $employee, ['assignee_user_id' => $manager->id]);

    $this->internal('POST', '/internal/v1/telegram/actions', ['bot_key' => 'platform', 'type' => 'q_dismiss', 'payload' => ['arg' => (string) $question->id], 'telegram_user_id' => 71, 'chat_id' => 71])
        ->assertOk()
        ->assertJsonCount(4, 'buttons')
        ->assertJsonPath('buttons.0.callback', 'q_dismiss:'.$question->id.':duplicate');

    $this->internal('POST', '/internal/v1/telegram/actions', ['bot_key' => 'platform', 'type' => 'q_dismiss', 'payload' => ['arg' => $question->id.':spam'], 'telegram_user_id' => 71, 'chat_id' => 71])
        ->assertOk()->assertJson(['ok' => true]);

    expect($question->refresh()->dismissed_reason)->toBe(DismissReason::Spam);
});

it('returns the employee history for the bot', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee, null, ['locale' => 'en']);
    $this->linkTelegram($employee, $workspace, 'platform', 81);
    $query = $this->aiQuery($workspace, $employee, ['question' => 'Wi-Fi?']);
    $question = $this->question($workspace, $employee, ['ai_query_id' => $query->id, 'status' => QuestionStatus::Answered]);
    $query->forceFill(['escalated_question_id' => $question->id])->save();
    $this->aiQuery($workspace, $employee, ['question' => 'Keys?', 'refused' => false]);

    $response = $this->internal('GET', '/internal/v1/opsbot/history?bot_key=platform&telegram_user_id=81&limit=5')->assertOk();

    expect(collect($response->json('items'))->map(fn ($item) => [$item['question'], $item['refused'], $item['manager_answered']])->all())->toBe([
        ['Keys?', false, false],
        ['Wi-Fi?', true, true],
    ]);

    expect(AiQuery::query()->count())->toBe(2);
});
