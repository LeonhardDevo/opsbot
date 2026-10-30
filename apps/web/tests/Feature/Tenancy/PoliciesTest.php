<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('exposes the permission map per role', function (WorkspaceRole $role, array $expected): void {
    $this->signInMember($role);

    $this->assertCan($this->get(route('dashboard'))->assertOk(), $expected);
})->with([
    'owner' => [WorkspaceRole::Owner, [
        'workspace.manage' => true, 'workspace.delete' => true, 'billing.manage' => true,
        'audit.view' => true, 'members.manage' => true, 'admin.ai' => false,
    ]],
    'admin' => [WorkspaceRole::Admin, [
        'workspace.manage' => true, 'workspace.delete' => false, 'billing.manage' => false,
        'billing.view' => true, 'audit.view' => true, 'members.manage' => true,
    ]],
    'member' => [WorkspaceRole::Employee, [
        'workspace.manage' => false, 'billing.view' => false, 'audit.view' => false,
        'members.manage' => false, 'knowledge.manage' => false, 'assistant.use' => true,
    ]],
]);

it('marks platform admins in the can map', function (): void {
    [$user] = $this->member();
    $user->forceFill(['is_platform_admin' => true])->save();

    $this->assertCan($this->actingAs($user)->get(route('dashboard')), ['admin.ai' => true]);
});

it('blocks members from management pages', function (string $route): void {
    $this->signInMember(WorkspaceRole::Employee);

    $this->get(route($route))->assertForbidden();
})->with(['audit.index', 'settings.billing', 'knowledge.index', 'employees.index', 'departments.index', 'bot.index', 'analytics.index', 'unanswered.index', 'settings.assistant']);

it('blocks members from managing the workspace and its people', function (): void {
    [$member, $workspace] = $this->signInMember(WorkspaceRole::Employee);

    $this->patch(route('settings.workspace.update'), ['name' => 'Hijack', 'timezone' => 'UTC', 'currency' => 'USD'])
        ->assertForbidden();
    $this->post(route('settings.members.invitations.store'), ['email' => 'x@example.com', 'role' => 'employee'])
        ->assertForbidden();
    $this->post(route('settings.billing.checkout'), ['plan' => 'pro', 'interval' => 'month'])
        ->assertForbidden();

    expect($workspace->refresh()->name)->not->toBe('Hijack');
});

it('lets admins view billing but not change it', function (): void {
    $this->signInMember(WorkspaceRole::Admin);

    $this->get(route('settings.billing'))->assertOk();
    $this->post(route('settings.billing.checkout'), ['plan' => 'pro', 'interval' => 'month'])->assertForbidden();
});

it('evaluates gates against the current workspace role', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    [$other] = $this->member(WorkspaceRole::Employee, $workspace);

    expect(Gate::forUser($user)->allows('members.manage'))->toBeTrue()
        ->and(Gate::forUser($other)->allows('members.manage'))->toBeFalse()
        ->and(Gate::forUser(User::factory()->create())->allows('knowledge.view'))->toBeFalse();
});

it('applies the document policy within the workspace only', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $draft = Document::factory()->create(['workspace_id' => $workspace->id]);
    $approved = Document::factory()->approved()->create(['workspace_id' => $workspace->id]);
    $foreign = Document::factory()->create();

    expect($manager->can('view', $draft))->toBeTrue()
        ->and($manager->can('update', $draft))->toBeTrue()
        ->and($manager->can('update', $approved))->toBeFalse()
        ->and($manager->can('delete', $draft))->toBeFalse()
        ->and($manager->can('view', $foreign))->toBeFalse();

    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $this->actingAs($employee);

    expect($employee->can('view', $draft))->toBeFalse();
});

it('restricts the AI admin area to platform admins', function (): void {
    $this->signInMember(WorkspaceRole::Owner);

    $this->get(route('admin.ai'))->assertForbidden();
    $this->post(route('admin.ai.test'))->assertForbidden();
});
