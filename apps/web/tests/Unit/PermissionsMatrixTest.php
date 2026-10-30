<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;

$matrix = [
    'workspace.manage' => ['owner', 'admin'],
    'workspace.delete' => ['owner'],
    'members.manage' => ['owner', 'admin'],
    'billing.view' => ['owner', 'admin'],
    'billing.manage' => ['owner'],
    'audit.view' => ['owner', 'admin'],
    'settings.assistant' => ['owner', 'admin'],
    'bot.manage' => ['owner', 'admin'],
    'departments.manage' => ['owner', 'admin'],
    'employees.manage' => ['owner', 'admin', 'manager'],
    'knowledge.view' => ['owner', 'admin', 'manager'],
    'knowledge.view_all' => ['owner', 'admin'],
    'knowledge.manage' => ['owner', 'admin', 'manager'],
    'knowledge.approve' => ['owner', 'admin'],
    'assistant.use' => ['owner', 'admin', 'manager', 'employee'],
    'assistant.history' => ['owner', 'admin', 'manager', 'employee'],
    'ai_queries.view_all' => ['owner', 'admin'],
    'ai_queries.view_department' => ['manager'],
    'incidents.create' => ['owner', 'admin', 'manager', 'employee'],
    'incidents.view_own' => ['owner', 'admin', 'manager', 'employee'],
    'incidents.view_department' => ['manager'],
    'incidents.view_all' => ['owner', 'admin'],
    'incidents.manage' => ['owner', 'admin', 'manager'],
    'tasks.create' => ['owner', 'admin', 'manager', 'employee'],
    'tasks.view_own' => ['owner', 'admin', 'manager', 'employee'],
    'tasks.view_department' => ['manager'],
    'tasks.view_all' => ['owner', 'admin'],
    'tasks.manage' => ['owner', 'admin', 'manager'],
    'questions.answer' => ['owner', 'admin', 'manager'],
    'analytics.view' => ['owner', 'admin', 'manager'],
    'integrations.manage' => ['owner', 'admin', 'manager', 'employee'],
];

it('matches the role × ability matrix of the contract', function (string $ability, array $roles): void {
    foreach (WorkspaceRole::cases() as $role) {
        expect($role->allows($ability))->toBe(in_array($role->value, $roles, true), "{$role->value} → {$ability}");
    }
})->with(array_map(fn (string $ability, array $roles): array => [$ability, $roles], array_keys($matrix), array_values($matrix)));

it('covers every ability of the catalog', function () use ($matrix): void {
    expect(array_keys($matrix))->toEqualCanonicalizing(config('permissions.abilities'));
});

it('ranks roles for management', function (): void {
    expect(array_map(fn (WorkspaceRole $role): int => $role->rank(), WorkspaceRole::cases()))->toBe([100, 50, 20, 10])
        ->and(WorkspaceRole::Manager->canManage(WorkspaceRole::Employee))->toBeTrue()
        ->and(WorkspaceRole::Manager->canManage(WorkspaceRole::Manager))->toBeFalse()
        ->and(WorkspaceRole::Manager->requiresWebKind())->toBeTrue()
        ->and(WorkspaceRole::Employee->requiresWebKind())->toBeFalse();
});
