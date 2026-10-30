<?php

declare(strict_types=1);

use App\Audit\Auditor;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\DepartmentMember;
use App\Models\OpsSettings;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Str;

it('creates the first workspace with a market, ops settings and a Pro trial', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('onboarding.workspace.store'), [
        'name' => 'Clean Team',
        'timezone' => 'Europe/Berlin',
        'country' => 'de',
        'dpa' => '1',
    ])->assertRedirect(route('onboarding.setup'));

    $workspace = Workspace::query()->sole();
    $subscription = Subscription::query()->sole();

    expect($workspace->country)->toBe('DE')
        ->and($workspace->currency)->toBe('EUR')
        ->and(OpsSettings::query()->sole()->min_confidence)->toBe(0.4)
        ->and($subscription->plan)->toBe('pro')
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($workspace->planKey())->toBe('pro');

    $this->post(route('onboarding.workspace.store'), ['name' => 'Second', 'timezone' => 'UTC', 'country' => 'RU', 'dpa' => '1'])->assertRedirect();

    expect(Workspace::query()->where('name', 'Second')->sole()->currency)->toBe('RUB')
        ->and(Subscription::query()->count())->toBe(1);
});

it('walks the setup wizard: steps, skip and finish', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);

    $this->get(route('onboarding.setup'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Setup', false)
            ->where('steps', [
                ['key' => 'workspace', 'state' => 'done'],
                ['key' => 'bot', 'state' => 'current'],
                ['key' => 'knowledge', 'state' => 'todo'],
                ['key' => 'team', 'state' => 'todo'],
            ])
            ->where('bot', null)
            ->has('starterPacks', 1)
            ->where('employeesCount', 0)
            ->where('driver', 'auto'));

    $this->post(route('onboarding.setup.skip'), ['step' => 'bot'])->assertRedirect();
    $this->post(route('onboarding.setup.skip'), ['step' => 'workspace'])->assertSessionHasErrors('step');
    $this->telegramEmployee($workspace);

    $this->get(route('onboarding.setup'))->assertInertia(fn ($page) => $page->where('steps', [
        ['key' => 'workspace', 'state' => 'done'],
        ['key' => 'bot', 'state' => 'skipped'],
        ['key' => 'knowledge', 'state' => 'current'],
        ['key' => 'team', 'state' => 'done'],
    ]));

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('workspace.onboarding_pending', true));
    $this->post(route('onboarding.setup.finish'))->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('workspace.onboarding_pending', false)->where('onboarding', null));

    $this->signInMember(WorkspaceRole::Manager);
    $this->get(route('onboarding.setup'))->assertForbidden();
});

it('edits assistant settings with bounds and audit', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $this->aiQuery($workspace, $employee, ['refused' => true]);
    $this->aiQuery($workspace, $employee, ['refused' => false]);

    $this->get(route('settings.assistant'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Assistant', false)
            ->where('settings', ['min_confidence' => 0.4, 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:00', 'reminders_enabled' => true])
            ->where('refusalRate30d', 0.5));

    $this->patch(route('settings.assistant.update'), ['min_confidence' => 0.9, 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00', 'reminders_enabled' => true])
        ->assertSessionHasErrors('min_confidence');
    $this->patch(route('settings.assistant.update'), ['min_confidence' => 0.55, 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:30', 'reminders_enabled' => false])
        ->assertSessionHas('success');

    expect($workspace->settings()->refresh()->toContract())->toBe(['min_confidence' => 0.55, 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:30', 'reminders_enabled' => false])
        ->and(AuditLog::query()->where('action', 'ops_settings.updated')->sole()->changes)->toHaveKeys(['min_confidence', 'quiet_hours_start', 'quiet_hours_end', 'reminders_enabled']);

    $this->actingAs($employee)->get(route('settings.assistant'))->assertForbidden();
});

it('exports the audit log as CSV only on plans with audit export', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    app(Auditor::class)->record('workspace.updated', $workspace, ['name' => ['=SUM(A1)', 'New']], actor: $owner, subjectLabel: '=cmd|calc');

    $this->get(route('audit.index'))->assertInertia(fn ($page) => $page->where('can', ['export' => false])->where('logs.data.0.source', 'web'));
    $this->get(route('audit.export'))->assertForbidden();

    Subscription::query()->create(['workspace_id' => $workspace->id, 'plan' => 'pro', 'status' => SubscriptionStatus::Active]);

    $csv = $this->get(route('audit.export', ['source' => 'web']))->assertOk()->streamedContent();
    expect($csv)->toStartWith('id,created_at,action')
        ->and($csv)->toContain("'=cmd|calc")
        ->and($csv)->toContain('workspace.updated');

    $this->get(route('audit.index', ['source' => 'bot']))->assertInertia(fn ($page) => $page->has('logs.data', 0));
});

it('invites web members into departments and puts them there on acceptance', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $department = $this->department($workspace, 'office');

    $this->post(route('settings.members.invitations.store'), ['email' => 'Dispatcher@Example.com', 'role' => 'manager', 'department_ids' => [$department->id]])->assertSessionHas('success');

    $invitation = WorkspaceInvitation::query()->sole();
    expect($invitation->department_ids)->toBe([$department->id]);

    $this->get(route('settings.members'))->assertInertia(fn ($page) => $page
        ->where('departments', [['id' => $department->id, 'name' => 'Office', 'code' => 'office']])
        ->where('invitations.0.department_ids', [$department->id]));

    $token = Str::random(64);
    $invitation->forceFill(['token_hash' => WorkspaceInvitation::hashToken($token)])->save();
    $invitee = User::factory()->create(['email' => 'dispatcher@example.com']);

    $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));

    expect($invitee->roleIn($workspace))->toBe(WorkspaceRole::Manager)
        ->and(DepartmentMember::query()->where('user_id', $invitee->id)->sole()->is_primary)->toBeTrue();
});

it('audits every view of the question log and scopes it to the viewer', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $mine = $this->department($workspace, 'mine');
    $this->inDepartment($manager, $mine);
    $this->aiQuery($workspace, $employee, ['department_ids_at_time' => [$mine->id]]);
    $this->aiQuery($workspace, $employee, ['department_ids_at_time' => []]);

    $this->get(route('assistant.history'))->assertInertia(fn ($page) => $page->has('queries.data', 1)->where('scope', 'department'));
    $this->get(route('assistant.history', ['status' => 'refused']))->assertOk();

    expect(AuditLog::query()->where('action', 'ai_query.viewed')->count())->toBe(2);

    $this->actingAs($employee)->get(route('assistant.history'))->assertInertia(fn ($page) => $page->has('queries.data', 2)->where('scope', 'self'));
});

it('limits internal opsbot routes to their callers', function (string $method, string $uri, string $wrongCaller): void {
    $this->internal($method, $uri, $method === 'POST' ? [] : null, $wrongCaller)->assertForbidden()->assertExactJson(['error' => 'caller_not_allowed']);
})->with([
    ['GET', '/internal/v1/opsbot/routing/resolve?workspace_id=1&user_id=1', 'worker'],
    ['GET', '/internal/v1/opsbot/incidents/1?workspace_id=1&user_id=1', 'worker'],
    ['GET', '/internal/v1/opsbot/history?bot_key=platform&telegram_user_id=1', 'ai'],
    ['POST', '/internal/v1/opsbot/reminders/dispatch', 'ai'],
    ['POST', '/internal/v1/opsbot/incidents', 'ai'],
    ['GET', '/internal/v1/telegram/bots/1/credentials', 'ai'],
    ['POST', '/internal/v1/telegram/notice/ack', 'ai'],
]);

it('resolves who handles a category for the ai service', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    [$owner] = $this->member(WorkspaceRole::Owner, $workspace);

    $this->internal('GET', '/internal/v1/opsbot/routing/resolve?workspace_id='.$workspace->id.'&user_id='.$employee->id.'&category=complaint', service: 'ai')
        ->assertOk()
        ->assertExactJson(['user' => ['id' => $owner->id, 'name' => $owner->name], 'department' => null, 'source' => 'workspace_owner']);

    [, $other] = $this->member(WorkspaceRole::Owner);
    $this->internal('GET', '/internal/v1/opsbot/routing/resolve?workspace_id='.$other->id.'&user_id='.$employee->id, service: 'ai')->assertNotFound();
});
