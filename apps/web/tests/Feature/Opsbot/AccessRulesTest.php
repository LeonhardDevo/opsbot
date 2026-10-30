<?php

declare(strict_types=1);

use App\Domain\Opsbot\DepartmentScope;
use App\Domain\Opsbot\DocumentVisibility;
use App\Domain\Opsbot\SeatCounter;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiQuery;
use App\Models\Incident;
use App\Models\Task;
use App\Models\UnansweredQuestion;
use App\Models\WorkspaceInvitation;

it('applies the document visibility truth table', function (array $docDepartments, array $docRoles, bool $privileged, bool $expected): void {
    expect(DocumentVisibility::visible($docDepartments, $docRoles, $privileged, 'employee', [1]))->toBe($expected);
})->with([
    'no dept, no roles' => [[], [], false, true],
    'dept match, no roles' => [[1, 2], [], false, true],
    'dept miss, no roles' => [[2], [], false, false],
    'no dept, role match' => [[], ['employee'], false, true],
    'no dept, role miss' => [[], ['manager'], false, false],
    'dept match, role match' => [[1], ['employee', 'manager'], false, true],
    'dept match, role miss' => [[1], ['manager'], false, false],
    'dept miss, role match' => [[3], ['employee'], false, false],
    'privileged sees everything' => [[3], ['manager'], true, true],
]);

it('scopes incidents by department, own records and privilege', function (): void {
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager);
    [$admin] = $this->member(WorkspaceRole::Admin, $workspace);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $mine = $this->department($workspace, 'mine');
    $other = $this->department($workspace, 'other');
    $this->inDepartment($manager, $mine);

    $inScope = $this->incident($workspace, ['department_id' => $mine->id, 'reporter_id' => $employee->id]);
    $outOfScope = $this->incident($workspace, ['department_id' => $other->id, 'reporter_id' => $employee->id]);
    $assignedToManager = $this->incident($workspace, ['department_id' => $other->id, 'assignee_id' => $manager->id]);
    $noDepartment = $this->incident($workspace, ['department_id' => null]);

    $scope = app(DepartmentScope::class);
    $managerActor = $this->actorFor($manager, $workspace);
    $adminActor = $this->actorFor($admin, $workspace);
    $employeeActor = $this->actorFor($employee, $workspace);

    $visible = fn ($actor): array => $scope->incidents(Incident::query()->withoutGlobalScopes(), $actor)->orderBy('id')->pluck('id')->all();

    expect($visible($managerActor))->toBe([$inScope->id, $assignedToManager->id])
        ->and($visible($adminActor))->toBe([$inScope->id, $outOfScope->id, $assignedToManager->id, $noDepartment->id])
        ->and($visible($employeeActor))->toBe([$inScope->id, $outOfScope->id])
        ->and($scope->canViewIncident($managerActor, $outOfScope))->toBeFalse()
        ->and($scope->canManageIncident($managerActor, $inScope))->toBeTrue()
        ->and($scope->canManageIncident($managerActor, $outOfScope))->toBeFalse()
        ->and($scope->canManageIncident($managerActor, $assignedToManager))->toBeTrue()
        ->and($scope->canManageIncident($employeeActor, $inScope))->toBeFalse()
        ->and($scope->canViewIncident($managerActor, $noDepartment))->toBeFalse();
});

it('scopes tasks by department, assignee and creator', function (): void {
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $mine = $this->department($workspace, 'mine');
    $other = $this->department($workspace, 'other');
    $this->inDepartment($manager, $mine);

    $inScope = $this->task($workspace, ['department_id' => $mine->id]);
    $outOfScope = $this->task($workspace, ['department_id' => $other->id, 'assignee_id' => $employee->id]);
    $created = $this->task($workspace, ['department_id' => $other->id, 'created_by' => $manager->id]);

    $scope = app(DepartmentScope::class);
    $managerActor = $this->actorFor($manager, $workspace);
    $employeeActor = $this->actorFor($employee, $workspace);

    expect($scope->tasks(Task::query()->withoutGlobalScopes(), $managerActor)->orderBy('id')->pluck('id')->all())->toBe([$inScope->id, $created->id])
        ->and($scope->tasks(Task::query()->withoutGlobalScopes(), $employeeActor)->pluck('id')->all())->toBe([$outOfScope->id])
        ->and($scope->canManageTask($managerActor, $inScope))->toBeTrue()
        ->and($scope->canManageTask($managerActor, $outOfScope))->toBeFalse()
        ->and($scope->canManageTask($managerActor, $created))->toBeTrue();
});

it('scopes questions and ai queries by department at the time', function (): void {
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $mine = $this->department($workspace, 'mine');
    $other = $this->department($workspace, 'other');
    $this->inDepartment($manager, $mine);

    $inScope = $this->question($workspace, $employee, ['department_id' => $mine->id]);
    $outOfScope = $this->question($workspace, $employee, ['department_id' => $other->id]);
    $assigned = $this->question($workspace, $employee, ['department_id' => $other->id, 'assignee_user_id' => $manager->id]);

    $queryIn = $this->aiQuery($workspace, $employee, ['department_ids_at_time' => [$mine->id, $other->id]]);
    $queryOut = $this->aiQuery($workspace, $employee, ['department_ids_at_time' => [$other->id]]);
    $queryOwn = $this->aiQuery($workspace, $manager, ['department_ids_at_time' => []]);

    $scope = app(DepartmentScope::class);
    $managerActor = $this->actorFor($manager, $workspace);
    $employeeActor = $this->actorFor($employee, $workspace);

    expect($scope->questions(UnansweredQuestion::query()->forWorkspace($workspace), $managerActor)->orderBy('id')->pluck('id')->all())->toBe([$inScope->id, $assigned->id])
        ->and($scope->canAnswerQuestion($managerActor, $outOfScope))->toBeFalse()
        ->and($scope->canAnswerQuestion($managerActor, $assigned))->toBeTrue()
        ->and($scope->canAnswerQuestion($employeeActor, $inScope))->toBeFalse()
        ->and($scope->aiQueries(AiQuery::query()->forWorkspace($workspace), $managerActor)->orderBy('id')->pluck('id')->all())->toBe([$queryIn->id, $queryOwn->id])
        ->and($scope->aiQueries(AiQuery::query()->forWorkspace($workspace), $employeeActor)->orderBy('id')->pluck('id')->all())->toBe([$queryIn->id, $queryOut->id])
        ->and($scope->aiQueryScope($managerActor))->toBe('department')
        ->and($scope->aiQueryScope($employeeActor))->toBe('self');
});

it('lets managers manage only lower-ranked employees of their departments', function (): void {
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager);
    [$otherManager] = $this->member(WorkspaceRole::Manager, $workspace);
    $mine = $this->department($workspace, 'mine');
    $other = $this->department($workspace, 'other');
    $this->inDepartment($manager, $mine);
    $this->inDepartment($otherManager, $mine);

    $inScope = $this->inDepartment($this->telegramEmployee($workspace, 'In'), $mine);
    $outOfScope = $this->inDepartment($this->telegramEmployee($workspace, 'Out'), $other);

    $scope = app(DepartmentScope::class);
    $actor = $this->actorFor($manager, $workspace);

    expect($scope->canManageEmployee($actor, $inScope))->toBeTrue()
        ->and($scope->canManageEmployee($actor, $outOfScope))->toBeFalse()
        ->and($scope->canManageEmployee($actor, $otherManager))->toBeFalse()
        ->and($scope->canManageEmployee($actor, $manager))->toBeFalse();
});

it('counts seats with the contract SQL', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $this->member(WorkspaceRole::Manager, $workspace);
    $this->telegramEmployee($workspace, 'Active');
    $this->telegramEmployee($workspace, 'Invited', MemberStatus::Invited);
    $this->telegramEmployee($workspace, 'Gone', MemberStatus::Offboarded);
    WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id]);
    WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id, 'expires_at' => now()->subDay()]);
    WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id, 'accepted_at' => now()]);

    $counter = app(SeatCounter::class);

    expect($counter->used($workspace->id))->toBe(5)
        ->and($counter->breakdown($workspace->id))->toBe(['web_members' => 2, 'employees_active' => 1, 'employees_invited' => 1]);
});
