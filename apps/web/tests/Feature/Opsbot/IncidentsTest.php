<?php

declare(strict_types=1);

use App\Domain\Opsbot\IncidentService;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\OutboxMessage;
use App\Models\RoutingRule;
use App\Models\Task;

it('derives the default severity per category', function (IncidentCategory $category, Severity $severity): void {
    expect(IncidentService::defaultSeverity($category))->toBe($severity);
})->with([
    [IncidentCategory::Injury, Severity::High],
    [IncidentCategory::Damage, Severity::Medium],
    [IncidentCategory::Complaint, Severity::Medium],
    [IncidentCategory::LostItem, Severity::Medium],
    [IncidentCategory::NoAccess, Severity::Low],
    [IncidentCategory::Other, Severity::Low],
]);

it('creates an incident from the web with routing, numbering, event, audit and a notification to the assignee', function (): void {
    [$employee, $workspace] = $this->signInMember(WorkspaceRole::Employee);
    $workspace->forceFill(['locale' => 'ru'])->save();
    [$manager] = $this->member(WorkspaceRole::Manager, $workspace, ['locale' => 'en']);
    $residential = $this->department($workspace, 'residential');
    $this->inDepartment($employee, $residential);
    RoutingRule::query()->create(['workspace_id' => $workspace->id, 'department_id' => $residential->id, 'category' => 'damage', 'manager_user_id' => $manager->id]);

    $this->post(route('incidents.store'), [
        'category' => 'damage',
        'description' => '  Поцарапала стол в гостиной у клиента, видна полоса 10 см  ',
        'client_ref' => 'ул. Ленина, 5',
        'assignee_id' => $employee->id,
    ])->assertRedirect();

    $incident = Incident::query()->sole();
    expect($incident->number)->toBe(1)
        ->and($incident->severity)->toBe(Severity::Medium)
        ->and($incident->status)->toBe(IncidentStatus::Open)
        ->and($incident->department_id)->toBe($residential->id)
        ->and($incident->assignee_id)->toBe($manager->id)
        ->and($incident->title)->toBe('Повреждение — Поцарапала стол в гостиной у клиента, видна полоса 10 см')
        ->and(IncidentEvent::query()->where('incident_id', $incident->id)->sole()->type->value)->toBe('created')
        ->and(AuditLog::query()->where('action', 'incident.created')->sole()->metadata['source'])->toBe('web');

    $message = OutboxMessage::query()->topic('opsbot.incident.created')->sole();
    expect($message->payload['recipient'])->toEqual(['user_id' => $manager->id, 'locale' => 'en'])
        ->and($message->payload['incident'])->toMatchArray([
            'display_number' => '#1',
            'category' => 'damage',
            'severity' => 'medium',
            'client_ref' => 'ул. Ленина, 5',
            'department_name' => 'Residential',
            'reporter_name' => $employee->name,
        ])
        ->and($message->payload['web_url'])->toEndWith('/incidents/'.$incident->id);

    $this->post(route('incidents.store'), ['category' => 'injury', 'description' => 'Cut my finger'])->assertRedirect();
    expect(Incident::query()->max('number'))->toBe(2);
});

it('lets managers pick the assignee and validates the payload', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    [$other] = $this->member(WorkspaceRole::Manager, $workspace);

    $this->post(route('incidents.store'), ['category' => 'other', 'severity' => 'high', 'description' => 'Broken cart', 'assignee_id' => $other->id]);
    $this->post(route('incidents.store'), ['category' => 'bogus', 'description' => 'x'])->assertSessionHasErrors(['category', 'description']);

    expect(Incident::query()->sole()->only(['assignee_id', 'severity']))->toEqual(['assignee_id' => $other->id, 'severity' => Severity::High])
        ->and($manager->exists)->toBeTrue();
});

it('lists incidents as board and table with counts, filters and scope', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $mine = $this->department($workspace, 'mine');
    $other = $this->department($workspace, 'other');
    $this->inDepartment($manager, $mine);

    $this->incident($workspace, ['department_id' => $mine->id, 'severity' => Severity::High]);
    $this->incident($workspace, ['department_id' => $mine->id, 'status' => IncidentStatus::Resolved, 'category' => IncidentCategory::NoAccess]);
    $this->incident($workspace, ['department_id' => $other->id]);

    $this->get(route('incidents.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('incidents/Index', false)
            ->where('view', 'board')
            ->has('incidents.data', 2)
            ->has('board.open', 1)
            ->has('board.resolved', 1)
            ->where('counts', ['open' => 1, 'in_progress' => 0, 'resolved' => 1, 'closed' => 0])
            ->where('board.open.0.display_number', '#1')
            ->where('can', ['create' => true, 'manage' => true]));

    $this->get(route('incidents.index', ['category' => 'no_access', 'view' => 'table']))
        ->assertInertia(fn ($page) => $page->where('view', 'table')->has('incidents.data', 1)->where('filters', ['category' => 'no_access']));
});

it('applies the incident state machine per role', function (): void {
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    [$assignee] = $this->member(WorkspaceRole::Employee, $workspace);

    $incident = $this->incident($workspace, ['reporter_id' => $employee->id, 'assignee_id' => $assignee->id]);
    $service = app(IncidentService::class);

    expect(array_map(fn (IncidentStatus $s): string => $s->value, $service->transitionsFor($this->actorFor($assignee, $workspace), $incident)))->toBe(['in_progress', 'resolved'])
        ->and($service->transitionsFor($this->actorFor($employee, $workspace), $incident))->toBe([])
        ->and(array_map(fn (IncidentStatus $s): string => $s->value, $service->transitionsFor($this->actorFor($owner, $workspace), $incident)))->toBe(['in_progress', 'resolved', 'closed']);

    $this->actingAs($employee)->patch(route('incidents.update', $incident), ['status' => 'resolved'])->assertSessionHas('error');
    $this->actingAs($assignee)->patch(route('incidents.update', $incident), ['status' => 'in_progress'])->assertSessionHas('success');
    $this->actingAs($assignee)->patch(route('incidents.update', $incident), ['status' => 'resolved', 'comment' => 'Paid for repair'])->assertSessionHas('success');

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::Resolved)
        ->and($incident->resolved_at)->not->toBeNull();

    $this->actingAs($assignee)->patch(route('incidents.update', $incident), ['status' => 'in_progress', 'comment' => 'Reopen'])->assertSessionHas('error');
    $this->actingAs($owner)->patch(route('incidents.update', $incident), ['status' => 'in_progress'])->assertSessionHas('error', __('errors.incidents.comment_required'));
    $this->actingAs($owner)->patch(route('incidents.update', $incident), ['status' => 'in_progress', 'comment' => 'Client called again'])->assertSessionHas('success');
    $this->actingAs($owner)->patch(route('incidents.update', $incident), ['status' => 'closed'])->assertSessionHas('error', __('errors.incidents.comment_required'));
    $this->actingAs($owner)->patch(route('incidents.update', $incident), ['status' => 'closed', 'comment' => 'Duplicate'])->assertSessionHas('success');
    $this->actingAs($owner)->patch(route('incidents.update', $incident), ['status' => 'open', 'comment' => 'x'])->assertSessionHas('error');

    $statuses = OutboxMessage::query()->topic('opsbot.incident.status_changed')->orderBy('created_at')->get()->map(fn ($m) => $m->payload['incident']['status'])->all();
    expect($statuses)->toBe(['in_progress', 'resolved', 'in_progress', 'closed'])
        ->and(AuditLog::query()->where('action', 'incident.status_changed')->count())->toBe(4)
        ->and(IncidentEvent::query()->where('type', 'status_changed')->count())->toBe(4);
});

it('takes an unassigned incident and assigns it to the actor', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $department = $this->department($workspace, 'mine');
    $this->inDepartment($manager, $department);
    $incident = $this->incident($workspace, ['department_id' => $department->id]);

    $this->patch(route('incidents.update', $incident), ['status' => 'in_progress'])->assertSessionHas('success');

    expect($incident->refresh()->assignee_id)->toBe($manager->id)
        ->and(IncidentEvent::query()->where('type', 'assigned')->exists())->toBeTrue();
});

it('restricts severity, category and assignee changes to managers in scope', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $mine = $this->department($workspace, 'mine');
    $other = $this->department($workspace, 'other');
    $this->inDepartment($manager, $mine);
    $inScope = $this->incident($workspace, ['department_id' => $mine->id]);
    $outOfScope = $this->incident($workspace, ['department_id' => $other->id]);

    $this->patch(route('incidents.update', $inScope), ['severity' => 'high', 'category' => 'complaint'])->assertSessionHas('success');
    $this->patch(route('incidents.update', $outOfScope), ['severity' => 'high'])->assertNotFound();

    expect($inScope->refresh()->severity)->toBe(Severity::High)
        ->and($inScope->category)->toBe(IncidentCategory::Complaint)
        ->and(IncidentEvent::query()->whereIn('type', ['severity_changed', 'category_changed'])->count())->toBe(2)
        ->and($outOfScope->refresh()->severity)->toBe(Severity::Medium);
});

it('shows an incident with events, transitions and comments', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $incident = $this->incident($workspace, ['reporter_id' => $owner->id]);

    $this->post(route('incidents.comments', $incident), ['body' => 'Called the client'])->assertSessionHas('success');

    $this->get(route('incidents.show', $incident))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('incidents/Show', false)
            ->where('incident.id', $incident->id)
            ->where('events.0.type', 'comment')
            ->where('events.0.body', 'Called the client')
            ->where('transitions', ['in_progress', 'resolved', 'closed'])
            ->where('sourceQuery', null)
            ->where('can', ['manage' => true, 'comment' => true, 'createTask' => true]));

    expect(AuditLog::query()->where('action', 'incident.commented')->exists())->toBeTrue();
});

it('creates a task from an incident and links it', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    [$employee] = $this->member(WorkspaceRole::Employee, $workspace);
    $department = $this->department($workspace, 'mine');
    $this->inDepartment($manager, $department);
    $incident = $this->incident($workspace, ['department_id' => $department->id]);

    $this->post(route('incidents.tasks', $incident), ['title' => 'Call the client', 'due_at' => '2026-12-01T10:00', 'assignee_id' => $employee->id])
        ->assertSessionHas('success');

    $task = Task::query()->sole();
    expect($task->incident_id)->toBe($incident->id)
        ->and($task->source->value)->toBe('incident')
        ->and($task->department_id)->toBe($department->id)
        ->and(IncidentEvent::query()->where('type', 'task_linked')->sole()->to_value)->toBe('1')
        ->and(OutboxMessage::query()->topic('opsbot.task.assigned')->sole()->payload['task']['incident_display_number'])->toBe('#1');
});

it('hides incidents of other workspaces and other employees', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Employee);
    [$colleague] = $this->member(WorkspaceRole::Employee, $workspace);
    [, $other] = $this->member(WorkspaceRole::Owner);
    $foreign = $this->incident($other);
    $colleagues = $this->incident($workspace, ['reporter_id' => $colleague->id]);

    $this->get(route('incidents.show', $foreign))->assertNotFound();
    $this->get(route('incidents.show', $colleagues))->assertNotFound();
    $this->get(route('incidents.index'))->assertInertia(fn ($page) => $page->has('incidents.data', 0)->where('can.manage', false));
});

it('reports incidents from the bot flow through the internal API', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee, null, ['locale' => 'ru']);
    [$owner] = $this->member(WorkspaceRole::Owner, $workspace);
    $bot = $this->companyBot($workspace);
    $this->linkTelegram($employee, $workspace, $bot->key(), 900000001);

    $response = $this->internal('POST', '/internal/v1/opsbot/incidents', [
        'bot_key' => $bot->key(),
        'telegram_user_id' => 900000001,
        'category' => 'no_access',
        'description' => 'Клиент не открывает дверь',
    ])->assertCreated();

    $incident = Incident::query()->sole();
    expect($response->json('incident'))->toBe(['id' => $incident->id, 'display_number' => '№ 1'])
        ->and($response->json('message_html'))->toBe('Инцидент <b>№ 1</b> создан. Ответственный: <b>'.e($owner->name).'</b>.')
        ->and($incident->source->value)->toBe('bot')
        ->and(AuditLog::query()->where('action', 'incident.created')->sole()->user_agent)->toBe('telegram');

    $this->internal('POST', '/internal/v1/opsbot/incidents', ['bot_key' => $bot->key(), 'telegram_user_id' => 1, 'category' => 'damage', 'description' => 'x'])->assertNotFound();
});

it('answers incident status lookups only within scope', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    [$owner] = $this->member(WorkspaceRole::Owner, $workspace);
    $own = $this->incident($workspace, ['reporter_id' => $employee->id, 'assignee_id' => $owner->id, 'status' => IncidentStatus::InProgress]);
    $foreign = $this->incident($workspace);

    $this->internal('GET', '/internal/v1/opsbot/incidents/'.$own->number.'?workspace_id='.$workspace->id.'&user_id='.$employee->id, service: 'ai')
        ->assertOk()
        ->assertJson(['display_number' => '#1', 'category' => 'damage', 'status' => 'in_progress', 'assignee_name' => $owner->name]);

    $this->internal('GET', '/internal/v1/opsbot/incidents/'.$foreign->number.'?workspace_id='.$workspace->id.'&user_id='.$employee->id, service: 'ai')->assertNotFound();
    $this->internal('GET', '/internal/v1/opsbot/incidents/'.$own->number.'?workspace_id='.$workspace->id.'&user_id='.$employee->id)->assertForbidden();
});

it('takes and resolves incidents from telegram callbacks', function (): void {
    [$manager, $workspace] = $this->member(WorkspaceRole::Manager, null, ['locale' => 'en']);
    $this->linkTelegram($manager, $workspace, 'platform', 555);
    $incident = $this->incident($workspace, ['assignee_id' => $manager->id]);

    $this->internal('POST', '/internal/v1/telegram/actions', ['bot_key' => 'platform', 'type' => 'incident_take', 'payload' => ['value' => 'incident_take:'.$incident->id, 'arg' => (string) $incident->id], 'telegram_user_id' => 555, 'chat_id' => 555])
        ->assertOk()->assertJson(['ok' => true, 'message' => 'Incident #1 is now in progress.']);
    $this->internal('POST', '/internal/v1/telegram/actions', ['bot_key' => 'platform', 'type' => 'incident_resolve', 'payload' => ['id' => $incident->id], 'telegram_user_id' => 555, 'chat_id' => 555])
        ->assertOk()->assertJson(['ok' => true]);

    expect($incident->refresh()->status)->toBe(IncidentStatus::Resolved)
        ->and(AuditLog::query()->where('action', 'incident.status_changed')->get()->every(fn ($log) => $log->metadata['source'] === 'bot'))->toBeTrue();
});
