<?php

declare(strict_types=1);

use App\Billing\PlanLimits;
use App\Billing\StripeEventProcessor;
use App\Domain\Opsbot\DocumentApprovalService;
use App\Domain\Opsbot\IncidentService;
use App\Domain\Opsbot\LimitNotices;
use App\Domain\Opsbot\SubscriptionState;
use App\Enums\ApprovalStatus;
use App\Enums\ArchiveReason;
use App\Enums\RefusalReason;
use App\Enums\SubscriptionState as State;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\Incident;
use App\Models\OutboxMessage;
use App\Models\Subscription;
use App\Models\User;
use App\Outbox\Outbox;
use App\Outbox\SecretInPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('derives grace and paused from past_due_since with an exact seven day boundary', function (): void {
    $subscription = new Subscription(['plan' => 'pro', 'status' => SubscriptionStatus::PastDue]);
    $subscription->past_due_since = CarbonImmutable::parse('2026-10-01 10:00:00');

    expect(SubscriptionState::of(null))->toBe(State::Ok)
        ->and(SubscriptionState::of(new Subscription(['plan' => 'pro', 'status' => SubscriptionStatus::Active])))->toBe(State::Ok)
        ->and(SubscriptionState::of($subscription, CarbonImmutable::parse('2026-10-08 10:00:00')))->toBe(State::Grace)
        ->and(SubscriptionState::of($subscription, CarbonImmutable::parse('2026-10-08 10:00:01')))->toBe(State::Paused)
        ->and(SubscriptionState::pausedAt($subscription)?->toIso8601String())->toBe('2026-10-08T10:00:00+00:00');
});

it('starts the grace period from a failed YooKassa renewal as well', function (): void {
    $subscription = new Subscription(['plan' => 'pro', 'status' => SubscriptionStatus::PastDue, 'gateway' => 'yookassa']);
    $subscription->payment_failed_at = CarbonImmutable::parse('2026-10-01 10:00:00');

    expect(SubscriptionState::of($subscription, CarbonImmutable::parse('2026-10-08 10:00:00')))->toBe(State::Grace)
        ->and(SubscriptionState::of($subscription, CarbonImmutable::parse('2026-10-08 10:00:01')))->toBe(State::Paused);
});

it('records past_due_since from billing events and clears it on recovery', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $processor = app(StripeEventProcessor::class);
    $event = fn (string $id, string $status, int $created): array => [
        'id' => $id, 'type' => 'customer.subscription.updated', 'created' => $created, 'object' => 'event',
        'data' => ['object' => ['id' => 'sub_1', 'customer' => 'cus_1', 'status' => $status, 'metadata' => ['workspace_id' => (string) $workspace->id, 'plan' => 'pro']]],
    ];

    $processor->handle($event('evt_1', 'past_due', 1_790_000_000));
    $since = Subscription::query()->sole()->past_due_since;
    $processor->handle($event('evt_2', 'past_due', 1_790_000_100));

    expect($since?->getTimestamp())->toBe(1_790_000_000)
        ->and(Subscription::query()->sole()->past_due_since?->getTimestamp())->toBe(1_790_000_000);

    $processor->handle($event('evt_3', 'active', 1_790_000_200));
    expect(Subscription::query()->sole()->past_due_since)->toBeNull();
});

it('stops only AI answers in the paused state', function (): void {
    Http::fake();
    [$employee, $workspace] = $this->signInMember(WorkspaceRole::Employee);
    Subscription::query()->create(['workspace_id' => $workspace->id, 'plan' => 'pro', 'status' => SubscriptionStatus::PastDue, 'past_due_since' => now()->subDays(8)]);

    $this->postJson(route('assistant.ask'), ['message' => 'What now?'])->assertStatus(423)->assertJson(['error' => 'paused']);
    $this->post(route('incidents.store'), ['category' => 'damage', 'description' => 'Still works'])->assertRedirect();

    Http::assertNothingSent();
    expect(Incident::query()->count())->toBe(1);

    $this->linkTelegram($employee, $workspace, 'platform', 77);
    $this->internal('GET', '/internal/v1/telegram/context?bot_key=platform&telegram_user_id=77')->assertJsonPath('workspace.subscription_state', 'paused');
});

it('counts monthly AI questions in the workspace timezone and excludes limit refusals', function (): void {
    CarbonImmutable::setTestNow('2026-11-01 10:00:00');
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    $workspace->forceFill(['timezone' => 'Europe/Moscow'])->save();

    $this->aiQuery($workspace, $employee, ['created_at' => '2026-10-31 21:30:00']);
    $this->aiQuery($workspace, $employee, ['created_at' => '2026-10-31 20:30:00']);
    $this->aiQuery($workspace, $employee, ['created_at' => '2026-11-01 09:00:00', 'refusal_reason' => RefusalReason::LimitReached]);

    expect(app(PlanLimits::class)->used($workspace, 'ai_queries_monthly'))->toBe(1);

    config(['billing.plans.free.limits.ai_queries_monthly' => 1]);
    $this->linkTelegram($employee, $workspace, 'platform', 88);
    $this->internal('GET', '/internal/v1/telegram/context?bot_key=platform&telegram_user_id=88')->assertJsonPath('limits.ai_queries_exhausted', true);
});

it('sends at most one limit notice per day after nine in the morning', function (): void {
    CarbonImmutable::setTestNow('2026-10-22 05:30:00');
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    $workspace->forceFill(['timezone' => 'Europe/Moscow'])->save();
    config(['billing.plans.free.limits.seats' => 1]);

    expect(app(LimitNotices::class)->tick())->toBe(0);

    CarbonImmutable::setTestNow('2026-10-22 06:30:00');
    $this->internal('POST', '/internal/v1/opsbot/limits/tick', [])->assertOk()->assertExactJson(['notices' => 1]);
    $this->internal('POST', '/internal/v1/opsbot/limits/tick', [])->assertOk()->assertExactJson(['notices' => 0]);

    $message = OutboxMessage::query()->topic('opsbot.limits.notice')->sole();
    expect($message->payload)->toMatchArray(['limit_key' => 'seats', 'used' => 1, 'limit' => 1])
        ->and($message->payload['recipient']['user_id'])->toBe($owner->id);

    CarbonImmutable::setTestNow('2026-10-23 07:00:00');
    expect(app(LimitNotices::class)->tick())->toBe(1);
});

it('archives predecessors when a future successor becomes effective on the local date', function (): void {
    CarbonImmutable::setTestNow('2026-11-30 20:30:00');
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $workspace->forceFill(['timezone' => 'Europe/Moscow'])->save();
    $v1 = Document::factory()->approved()->create(['workspace_id' => $workspace->id]);
    Document::factory()->approved('2026-12-01')->create(['workspace_id' => $workspace->id, 'supersedes_document_id' => $v1->id]);

    expect(app(DocumentApprovalService::class)->tick()['archived'])->toBe(0);

    CarbonImmutable::setTestNow('2026-11-30 21:00:00');
    $this->internal('POST', '/internal/v1/opsbot/documents/tick', [])->assertOk()->assertJson(['archived' => 1]);

    expect($v1->refresh()->approval_status)->toBe(ApprovalStatus::Archived)
        ->and($v1->archive_reason)->toBe(ArchiveReason::Superseded)
        ->and(OutboxMessage::query()->topic('document.metadata_changed')->sole()->payload['archived'])->toBeTrue();
});

it('sends review notices to the approver once every thirty days', function (): void {
    CarbonImmutable::setTestNow('2026-10-22 12:00:00');
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    [$admin] = $this->member(WorkspaceRole::Admin, $workspace);
    $due = Document::factory()->approved('2025-01-01')->create(['workspace_id' => $workspace->id, 'review_due_at' => '2026-10-01', 'approved_by' => $admin->id]);
    Document::factory()->approved('2025-01-01')->create(['workspace_id' => $workspace->id, 'review_due_at' => '2027-01-01']);

    expect(app(DocumentApprovalService::class)->tick()['review_notices'])->toBe(1)
        ->and(app(DocumentApprovalService::class)->tick()['review_notices'])->toBe(0)
        ->and(OutboxMessage::query()->topic('opsbot.document.review_due')->sole()->payload['recipient']['user_id'])->toBe($admin->id)
        ->and($due->refresh()->review_notified_on?->toDateString())->toBe('2026-10-22');

    CarbonImmutable::setTestNow('2026-11-21 12:00:00');
    expect(app(DocumentApprovalService::class)->tick()['review_notices'])->toBe(1)
        ->and($owner->exists)->toBeTrue();
});

it('falls back to e-mail for web users when telegram is not linked', function (): void {
    config(['mail.default' => 'outbox']);
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager, null, ['locale' => 'ru']);
    $employee = $this->telegramEmployee($workspace);
    $incident = $this->incident($workspace, ['assignee_id' => $manager->id]);
    app(IncidentService::class)->notifyCreated($incident, $manager, $employee);
    app(IncidentService::class)->notifyCreated($incident, $employee, $manager);
    [$toManager, $toEmployee] = OutboxMessage::query()->topic('opsbot.incident.created')->orderBy('created_at')->get()->all();

    $report = fn (OutboxMessage $message, string $error) => $this->internal('POST', '/internal/v1/deliveries', [
        'reference' => ['type' => 'opsbot.incident.created', 'id' => $message->id],
        'channel' => 'telegram', 'status' => 'failed', 'error' => $error,
    ])->assertCreated();

    $report($toManager, 'telegram_not_linked');
    $report($toEmployee, 'telegram_not_linked');
    $report($toManager, 'rate_limited');

    $email = OutboxMessage::query()->topic('email.send')->sole();
    expect($email->payload['to'])->toBe($manager->email)
        ->and($email->payload['subject'])->toBe('Новый инцидент № 1')
        ->and($email->payload['reference']['type'])->toBe('email_fallback')
        ->and($email->payload['html'])->toContain('/incidents/'.$incident->id);
});

it('rejects secrets in outbox payloads', function (array $payload): void {
    app(Outbox::class)->publish('telegram.notify', $payload);
})->throws(SecretInPayload::class)->with([
    'token key' => [['token' => 'abc']],
    'nested secret' => [['bot' => ['webhook_secret' => 'x']]],
    'password key' => [['Password' => 'x']],
    'bot token value' => [['text' => '123456789:TESTaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']],
]);

it('accepts normal payloads in the outbox', function (): void {
    $message = app(Outbox::class)->publish('telegram.notify', ['text_html' => 'Hello', 'tokens_used' => 5]);

    expect($message->exists)->toBeTrue()
        ->and(User::query()->count())->toBe(0);
});

it('ends a local trial at its end date and never treats it as a provider subscription', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $subscription = Subscription::query()->create([
        'workspace_id' => $workspace->id,
        'gateway' => 'stripe',
        'plan' => 'pro',
        'status' => SubscriptionStatus::Trialing,
        'interval' => 'month',
        'trial_ends_at' => now()->addDay(),
        'current_period_end' => now()->addDay(),
    ]);

    expect($subscription->isLocalTrial())->toBeTrue()
        ->and($subscription->isProviderManaged())->toBeFalse()
        ->and($workspace->fresh()?->planKey())->toBe('pro');

    $this->travel(2)->days();

    expect($workspace->fresh()?->planKey())->toBe('free');

    $subscription->forceFill(['stripe_subscription_id' => 'sub_live', 'trial_ends_at' => now()->subDay()])->save();

    expect($subscription->isLocalTrial())->toBeFalse()
        ->and($subscription->isProviderManaged())->toBeTrue()
        ->and($workspace->fresh()?->planKey())->toBe('pro');
});
