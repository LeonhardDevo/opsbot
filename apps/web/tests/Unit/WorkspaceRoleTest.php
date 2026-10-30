<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;

it('grants owners every ability except the department-scoped variants', function (): void {
    expect(WorkspaceRole::Owner->abilities())->toEqualCanonicalizing(array_values(array_diff(
        config('permissions.abilities'),
        ['incidents.view_department', 'tasks.view_department', 'ai_queries.view_department'],
    )));
});

it('grants admins management abilities except deleting the workspace and billing changes', function (): void {
    $admin = WorkspaceRole::Admin;

    expect($admin->allows('members.manage'))->toBeTrue()
        ->and($admin->allows('audit.view'))->toBeTrue()
        ->and($admin->allows('billing.view'))->toBeTrue()
        ->and($admin->allows('billing.manage'))->toBeFalse()
        ->and($admin->allows('workspace.delete'))->toBeFalse();
});

it('limits employees to using the product', function (): void {
    expect(WorkspaceRole::Employee->abilities())->toEqualCanonicalizing([
        'assistant.use', 'assistant.history', 'incidents.create', 'incidents.view_own',
        'tasks.create', 'tasks.view_own', 'integrations.manage',
    ]);
});

it('never grants abilities missing from the catalog', function (): void {
    config(['permissions.roles.employee' => ['assistant.use', 'not.in.catalog']]);

    expect(WorkspaceRole::Employee->abilities())->toBe(['assistant.use']);
});

it('applies the management hierarchy', function (): void {
    expect(WorkspaceRole::Owner->canManage(WorkspaceRole::Owner))->toBeTrue()
        ->and(WorkspaceRole::Admin->canManage(WorkspaceRole::Employee))->toBeTrue()
        ->and(WorkspaceRole::Admin->canManage(WorkspaceRole::Admin))->toBeFalse()
        ->and(WorkspaceRole::Admin->canManage(WorkspaceRole::Owner))->toBeFalse()
        ->and(WorkspaceRole::Employee->canManage(WorkspaceRole::Employee))->toBeFalse();
});

it('lets owners assign any role and admins only lower ones', function (): void {
    expect(WorkspaceRole::Owner->assignableRoles())->toBe(WorkspaceRole::cases())
        ->and(WorkspaceRole::Admin->assignableRoles())->toBe([WorkspaceRole::Manager, WorkspaceRole::Employee])
        ->and(WorkspaceRole::Employee->assignableRoles())->toBe([]);
});

it('falls back to a readable label when no translation exists', function (): void {
    expect(WorkspaceRole::Admin->label())->toBeString()->not->toBe('roles.admin');
});
