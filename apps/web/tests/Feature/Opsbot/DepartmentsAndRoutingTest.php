<?php

declare(strict_types=1);

use App\Domain\Opsbot\RoutingResolver;
use App\Enums\MemberStatus;
use App\Enums\RoutingCategory;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\RoutingRule;
use App\Models\WorkspaceMembership;

it('resolves the routing fallback chain and skips inactive managers', function (): void {
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    [$ruleManager] = $this->member(WorkspaceRole::Manager, $workspace);
    [$anyManager] = $this->member(WorkspaceRole::Manager, $workspace);
    [$departmentManager] = $this->member(WorkspaceRole::Manager, $workspace);
    $residential = $this->department($workspace, 'residential', $departmentManager);
    $commercial = $this->department($workspace, 'commercial');

    RoutingRule::query()->create(['workspace_id' => $workspace->id, 'department_id' => $residential->id, 'category' => 'damage', 'manager_user_id' => $ruleManager->id]);
    RoutingRule::query()->create(['workspace_id' => $workspace->id, 'department_id' => null, 'category' => 'damage', 'manager_user_id' => $anyManager->id]);

    $resolver = app(RoutingResolver::class);

    expect($resolver->resolve($workspace, RoutingCategory::Damage, $residential->id)['user']?->id)->toBe($ruleManager->id)
        ->and($resolver->resolve($workspace, RoutingCategory::Damage, $residential->id)['source'])->toBe('rule')
        ->and($resolver->resolve($workspace, RoutingCategory::Damage, $commercial->id)['user']?->id)->toBe($anyManager->id)
        ->and($resolver->resolve($workspace, RoutingCategory::Complaint, $residential->id))->toMatchArray(['source' => 'department_manager'])
        ->and($resolver->resolve($workspace, RoutingCategory::Complaint, $residential->id)['user']?->id)->toBe($departmentManager->id)
        ->and($resolver->resolve($workspace, RoutingCategory::Complaint, $commercial->id)['user']?->id)->toBe($owner->id)
        ->and($resolver->resolve($workspace, RoutingCategory::Complaint, $commercial->id)['source'])->toBe('workspace_owner');

    WorkspaceMembership::query()->where('user_id', $ruleManager->id)->update(['status' => MemberStatus::Offboarded->value]);
    expect($resolver->resolve($workspace, RoutingCategory::Damage, $residential->id)['user']?->id)->toBe($anyManager->id);

    WorkspaceMembership::query()->where('user_id', $anyManager->id)->update(['role' => 'employee']);
    expect($resolver->resolve($workspace, RoutingCategory::Damage, $residential->id)['user']?->id)->toBe($departmentManager->id);
});

it('renders the departments page with rules and categories', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    [$manager] = $this->member(WorkspaceRole::Manager, $workspace);
    $residential = $this->department($workspace, 'residential', $manager);
    $this->inDepartment($this->telegramEmployee($workspace), $residential);
    RoutingRule::query()->create(['workspace_id' => $workspace->id, 'department_id' => $residential->id, 'category' => 'damage', 'manager_user_id' => $manager->id]);

    $this->get(route('departments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('departments/Index', false)
            ->where('departments.0.code', 'residential')
            ->where('departments.0.manager', ['id' => $manager->id, 'name' => $manager->name])
            ->where('departments.0.members_count', 1)
            ->where('departments.0.documents_count', 0)
            ->where('rules.0.category', 'damage')
            ->has('categories', 8)
            ->where('fallbackOwner', null));

    expect($owner->exists)->toBeTrue();
});

it('creates, updates and archives departments with audit and unique names', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$manager] = $this->member(WorkspaceRole::Manager, $workspace);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);

    $this->post(route('departments.store'), ['name' => 'Уборка квартир', 'manager_user_id' => $manager->id])->assertSessionHas('success');
    $department = Department::query()->sole();

    expect($department->code)->toBe('uborka-kvartir')
        ->and($department->manager_user_id)->toBe($manager->id);

    $this->post(route('departments.store'), ['name' => 'уборка квартир'])->assertSessionHas('error', __('errors.departments.name_taken'));
    $this->post(route('departments.store'), ['name' => 'Office', 'manager_user_id' => $employee->id])->assertSessionHas('error', __('errors.departments.manager_invalid'));

    $this->patch(route('departments.update', $department), ['name' => 'Residential'])->assertSessionHas('success');
    $this->delete(route('departments.destroy', $department))->assertSessionHas('success');

    expect($department->refresh()->archived_at)->not->toBeNull()
        ->and(AuditLog::query()->whereIn('action', ['department.created', 'department.updated', 'department.archived'])->count())->toBe(3);

    $this->post(route('departments.store'), ['name' => 'Residential'])->assertSessionHas('success');
});

it('replaces routing rules atomically and rejects duplicates', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$manager] = $this->member(WorkspaceRole::Manager, $workspace);
    $department = $this->department($workspace, 'residential');
    RoutingRule::query()->create(['workspace_id' => $workspace->id, 'department_id' => null, 'category' => 'task', 'manager_user_id' => $owner->id]);

    $this->put(route('departments.routing'), ['rules' => [
        ['department_id' => $department->id, 'category' => 'damage', 'manager_user_id' => $manager->id],
        ['department_id' => null, 'category' => 'injury', 'manager_user_id' => $owner->id],
    ]])->assertSessionHas('success');

    expect(RoutingRule::query()->orderBy('category')->pluck('category')->map->value->all())->toBe(['damage', 'injury'])
        ->and(AuditLog::query()->where('action', 'routing_rules.updated')->exists())->toBeTrue();

    $this->put(route('departments.routing'), ['rules' => [
        ['department_id' => null, 'category' => 'injury', 'manager_user_id' => $owner->id],
        ['department_id' => null, 'category' => 'injury', 'manager_user_id' => $manager->id],
    ]])->assertSessionHas('error', __('errors.routing.duplicate'));

    expect(RoutingRule::query()->count())->toBe(2);
});

it('previews the routing for the incident dialog', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Employee);
    [$realOwner] = $this->member(WorkspaceRole::Owner, $workspace);

    $this->getJson(route('routing.preview', ['category' => 'damage']))
        ->assertOk()
        ->assertExactJson(['user' => ['id' => $realOwner->id, 'name' => $realOwner->name], 'source' => 'workspace_owner']);

    expect($owner->exists)->toBeTrue();
});

it('forbids managers and employees from managing departments', function (WorkspaceRole $role): void {
    $this->signInMember($role);

    $this->get(route('departments.index'))->assertForbidden();
    $this->post(route('departments.store'), ['name' => 'Office'])->assertSessionHas('error');
    expect(Department::query()->count())->toBe(0);
})->with([WorkspaceRole::Manager, WorkspaceRole::Employee]);

it('never exposes departments of another workspace', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [, $other] = $this->member(WorkspaceRole::Owner);
    $foreign = $this->department($other, 'foreign');

    $this->patch(route('departments.update', $foreign), ['name' => 'Hijack'])->assertNotFound();
    expect($foreign->refresh()->name)->toBe('Foreign')
        ->and($workspace->exists)->toBeTrue();
});
