<?php

declare(strict_types=1);

use App\Domain\Opsbot\Jobs\BuildEmployeeExport;
use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiQuery;
use App\Models\AuditLog;
use App\Models\DepartmentMember;
use App\Models\EmployeeExport;
use App\Models\EmployeeLink;
use App\Models\OutboxMessage;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Notifications\EmployeeExportReady;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    Storage::fake('documents');
});

it('creates a telegram-only employee within the seat limit', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $residential = $this->department($workspace, 'residential');
    $commercial = $this->department($workspace, 'commercial');

    $this->post(route('employees.store'), [
        'name' => 'Айгуль Сапарова',
        'department_ids' => [$residential->id, $commercial->id],
        'primary_department_id' => $commercial->id,
        'phone' => '+7 (999) 123-45-67',
        'position' => 'Клинер',
        'locale' => 'ru',
    ])->assertSessionHas('success');

    $employee = User::query()->where('name', 'Айгуль Сапарова')->sole();
    $membership = $employee->membershipIn($workspace);

    expect($employee->email)->toBeNull()
        ->and($employee->password)->toBeNull()
        ->and($membership?->kind)->toBe(MemberKind::TelegramOnly)
        ->and($membership?->status)->toBe(MemberStatus::Invited)
        ->and($membership?->role)->toBe(WorkspaceRole::Employee)
        ->and($membership?->phone)->toBe('+79991234567')
        ->and(DepartmentMember::query()->where('user_id', $employee->id)->where('is_primary', true)->value('department_id'))->toBe($commercial->id)
        ->and(AuditLog::query()->where('action', 'employee.created')->exists())->toBeTrue()
        ->and($owner->exists)->toBeTrue();

    $this->post(route('employees.store'), ['name' => 'Bad phone', 'department_ids' => [], 'phone' => '12', 'locale' => 'en'])->assertSessionHasErrors('phone');
});

it('blocks employee creation over the seat limit and flags the limit for the upgrade dialog', function (): void {
    $this->signInMember(WorkspaceRole::Owner);
    config(['billing.plans.free.limits.seats' => 1]);

    $this->post(route('employees.store'), ['name' => 'Ivan', 'department_ids' => [], 'locale' => 'ru'])
        ->assertSessionHas('limit', 'seats')
        ->assertSessionHas('error', __('errors.limits.seats', ['count' => 1]));

    expect(User::query()->count())->toBe(1);
});

it('keeps managers within their departments', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $mine = $this->department($workspace, 'mine');
    $other = $this->department($workspace, 'other');
    $this->inDepartment($manager, $mine);

    $this->post(route('employees.store'), ['name' => 'Out', 'department_ids' => [$other->id], 'locale' => 'ru'])->assertSessionHas('error');
    $this->post(route('employees.store'), ['name' => 'Nowhere', 'department_ids' => [], 'locale' => 'ru'])->assertSessionHas('error');
    $this->post(route('employees.store'), ['name' => 'In', 'department_ids' => [$mine->id], 'locale' => 'ru'])->assertSessionHas('success');

    $outsider = $this->inDepartment($this->telegramEmployee($workspace, 'Outsider'), $other);
    $this->patch(route('employees.update', $outsider), ['name' => 'Renamed'])->assertSessionHas('error');
    $this->delete(route('employees.destroy', $outsider))->assertSessionHas('error');

    expect($outsider->refresh()->name)->toBe('Outsider')
        ->and(User::query()->where('name', 'In')->exists())->toBeTrue();
});

it('lists employees with telegram state, links, seats and relink counters', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $bot = $this->companyBot($workspace);
    $department = $this->department($workspace, 'residential');
    $linked = $this->inDepartment($this->telegramEmployee($workspace, 'Linked'), $department);
    $this->linkTelegram($linked, $workspace, $bot->key(), 900000001);
    $relink = $this->telegramEmployee($workspace, 'Needs relink');
    $invited = $this->telegramEmployee($workspace, 'Invited', MemberStatus::Invited);
    EmployeeLink::query()->create(['workspace_id' => $workspace->id, 'user_id' => $invited->id, 'bot_key' => $bot->key(), 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);
    $this->aiQuery($workspace, $linked);

    $response = $this->get(route('employees.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('employees/Index', false)
        ->has('employees.data', 4)
        ->where('seats', ['used' => 4, 'limit' => 5])
        ->where('bot', ['status' => 'active', 'username' => $bot->username])
        ->where('pendingRelink', 1));

    $rows = collect($response->viewData('page')['props']['employees']['data'])->keyBy('name');
    expect($rows['Linked']['telegram']['state'])->toBe('linked')
        ->and($rows['Linked']['departments'][0]['code'])->toBe('residential')
        ->and($rows['Linked']['last_question_at'])->not->toBeNull()
        ->and($rows['Needs relink']['needs_relink'])->toBeTrue()
        ->and($rows['Invited']['link'])->toMatchArray(['active' => true])
        ->and($rows[$owner->name]['is_you'])->toBeTrue();

    $this->get(route('employees.index', ['telegram' => 'not_linked']))->assertInertia(fn ($page) => $page->has('employees.data', 3));
    $this->get(route('employees.index', ['needs_relink' => 1]))->assertInertia(fn ($page) => $page->has('employees.data', 1));
    $this->get(route('employees.index', ['department_id' => $department->id]))->assertInertia(fn ($page) => $page->has('employees.data', 1));
});

it('issues single-use invite links for the active bot and revokes older ones', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $bot = $this->companyBot($workspace);
    $employee = $this->telegramEmployee($workspace, 'Ivan', MemberStatus::Invited);

    $first = $this->postJson(route('employees.link', $employee))->assertOk();
    $second = $this->postJson(route('employees.link', $employee))->assertOk();

    expect($second->json('url'))->toMatch('#^https://t\.me/test_7000000001_bot\?start=emp_[A-Za-z0-9]{40}$#')
        ->and($second->json('bot_username'))->toBe('test_7000000001_bot')
        ->and($second->json('user_id'))->toBe($employee->id)
        ->and(EmployeeLink::query()->whereNull('revoked_at')->count())->toBe(1)
        ->and(EmployeeLink::query()->whereNotNull('revoked_at')->count())->toBe(1)
        ->and(OutboxMessage::query()->get()->every(fn ($m) => ! str_contains(json_encode($m->payload), 'emp_')))->toBeTrue()
        ->and($first->json('url'))->not->toBe($second->json('url'))
        ->and(EmployeeLink::query()->first()?->bot_key)->toBe($bot->key());

    $links = $this->postJson(route('employees.links.bulk'), ['user_ids' => [$employee->id]])->assertOk();
    expect($links->json('links'))->toHaveCount(1);

    $this->postJson(route('employees.links.bulk'), ['user_ids' => range(1, 51)])->assertUnprocessable();
});

it('redeems employee links on the right bot only', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $bot = $this->companyBot($workspace);
    $employee = $this->telegramEmployee($workspace, 'Ivan', MemberStatus::Invited);
    $url = $this->postJson(route('employees.link', $employee))->json('url');
    $token = substr($url, strpos($url, '=') + 1);
    $identity = ['telegram_user_id' => 900000002, 'chat_id' => 900000002, 'username' => 'ivan', 'language_code' => 'ru'];

    $this->internal('POST', '/internal/v1/telegram/link', ['bot_key' => 'platform', 'token' => $token, ...$identity])
        ->assertStatus(422)->assertJson(['error' => 'wrong_bot']);

    $this->internal('POST', '/internal/v1/telegram/link', ['bot_key' => $bot->key(), 'token' => $token, ...$identity])
        ->assertOk()
        ->assertJson(['user' => ['id' => $employee->id, 'name' => 'Ivan', 'locale' => 'ru'], 'notice_required' => true]);

    $this->internal('POST', '/internal/v1/telegram/link', ['bot_key' => $bot->key(), 'token' => $token, ...$identity])
        ->assertStatus(410)->assertJson(['error' => 'token_used']);

    expect($employee->membershipIn($workspace)?->status)->toBe(MemberStatus::Active)
        ->and(TelegramAccount::query()->sole()->telegram_bot_id)->toBe($bot->id)
        ->and(AuditLog::query()->where('action', 'employee.linked')->sole()->user_agent)->toBe('telegram');
});

it('rejects expired, revoked and conflicting employee links', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $bot = $this->companyBot($workspace);
    $first = $this->telegramEmployee($workspace, 'First', MemberStatus::Invited);
    $second = $this->telegramEmployee($workspace, 'Second', MemberStatus::Invited);
    $this->linkTelegram($first, $workspace, $bot->key(), 42);

    $token = fn (User $user): string => substr($url = $this->postJson(route('employees.link', $user))->json('url'), strpos($url, '=') + 1);

    $conflict = $token($second);
    $this->internal('POST', '/internal/v1/telegram/link', ['bot_key' => $bot->key(), 'token' => $conflict, 'telegram_user_id' => 42, 'chat_id' => 42])
        ->assertStatus(409)->assertJson(['error' => 'conflict']);

    $revoked = $token($second);
    $token($second);
    $this->internal('POST', '/internal/v1/telegram/link', ['bot_key' => $bot->key(), 'token' => $revoked, 'telegram_user_id' => 43, 'chat_id' => 43])
        ->assertStatus(410)->assertJson(['error' => 'token_expired']);

    $expired = $token($second);
    $this->travel(8)->days();
    $this->internal('POST', '/internal/v1/telegram/link', ['bot_key' => $bot->key(), 'token' => $expired, 'telegram_user_id' => 43, 'chat_id' => 43])
        ->assertStatus(410)->assertJson(['error' => 'token_expired']);

    $this->internal('POST', '/internal/v1/telegram/link', ['bot_key' => $bot->key(), 'token' => 'emp_'.str_repeat('z', 40), 'telegram_user_id' => 43, 'chat_id' => 43])
        ->assertNotFound()->assertJson(['error' => 'invalid_token']);
});

it('refuses invite links when no bot is available', function (): void {
    config(['product.telegram.bot_username' => null]);
    [, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $employee = $this->telegramEmployee($workspace);

    $this->postJson(route('employees.link', $employee))->assertStatus(422)->assertJson(['error' => 'bot.not_connected']);
});

it('updates employees, audits the diff and invalidates the bot context', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $department = $this->department($workspace, 'commercial');
    $employee = $this->telegramEmployee($workspace);

    $this->patch(route('employees.update', $employee), ['department_ids' => [$department->id], 'position' => 'Senior'])->assertSessionHas('success');
    $this->patch(route('employees.update', $employee), ['role' => 'manager'])->assertSessionHas('error', __('errors.employees.role_requires_web'));

    expect(AuditLog::query()->where('action', 'employee.updated')->sole()->changes)->toHaveKeys(['position', 'department_ids', 'primary_department_id'])
        ->and(OutboxMessage::query()->topic('telegram.context.invalidate')->sole()->payload)->toMatchArray(['workspace_id' => $workspace->id, 'user_id' => $employee->id]);
});

it('offboards an employee, reassigns open tasks, notifies through the bot and unlinks', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    [$manager] = $this->member(WorkspaceRole::Manager, $workspace);
    $bot = $this->companyBot($workspace);
    $department = $this->department($workspace, 'residential', $manager);
    $employee = $this->inDepartment($this->telegramEmployee($workspace), $department);
    $this->linkTelegram($employee, $workspace, $bot->key(), 900);
    $task = $this->task($workspace, ['assignee_id' => $employee->id, 'department_id' => $department->id]);
    $done = $this->task($workspace, ['assignee_id' => $employee->id, 'status' => TaskStatus::Done]);

    $this->delete(route('employees.destroy', $employee))->assertSessionHas('success');

    $notify = OutboxMessage::query()->topic('telegram.notify')->sole();
    expect($employee->membershipIn($workspace)?->status)->toBe(MemberStatus::Offboarded)
        ->and($task->refresh()->assignee_id)->toBe($manager->id)
        ->and($done->refresh()->assignee_id)->toBe($employee->id)
        ->and(TelegramAccount::query()->count())->toBe(0)
        ->and($notify->payload)->toMatchArray(['workspace_id' => $workspace->id, 'bot_key' => $bot->key(), 'chat_id' => 900, 'template' => 'opsbot.access_revoked'])
        ->and(OutboxMessage::query()->topic('telegram.context.invalidate')->sole()->payload['keys'])->toBe([['bot_key' => $bot->key(), 'telegram_user_id' => 900]])
        ->and(OutboxMessage::query()->topic('opsbot.task.assigned')->sole()->payload['recipient']['user_id'])->toBe($manager->id)
        ->and(AuditLog::query()->where('action', 'employee.offboarded')->sole()->metadata['tasks_reassigned'])->toBe(1)
        ->and($owner->exists)->toBeTrue();

    $this->delete(route('employees.destroy', $employee))->assertSessionHas('error');
});

it('reactivates offboarded employees with a new link and seat check', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $employee = $this->telegramEmployee($workspace, 'Back', MemberStatus::Offboarded);

    $this->postJson(route('employees.reactivate', $employee))->assertOk()->assertJsonPath('user_id', $employee->id);
    expect($employee->membershipIn($workspace)?->status)->toBe(MemberStatus::Invited)
        ->and(AuditLog::query()->where('action', 'employee.reactivated')->exists())->toBeTrue();

    $this->postJson(route('employees.reactivate', $employee))->assertStatus(422);
});

it('exports employee data as a zip and notifies the requester', function (): void {
    Notification::fake();
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $employee = $this->telegramEmployee($workspace, 'Exported');
    $this->linkTelegram($employee, $workspace, 'platform', 1234);
    $this->aiQuery($workspace, $employee, ['question' => 'Wi-Fi?']);
    $this->incident($workspace, ['reporter_id' => $employee->id]);

    $this->get(route('employees.export', $employee))->assertSessionHas('success');

    $export = EmployeeExport::query()->sole();
    expect($export->status)->toBe('ready')
        ->and(AuditLog::query()->where('action', 'employee.data_exported')->exists())->toBeTrue();
    Notification::assertSentTo($owner, EmployeeExportReady::class);

    $data = BuildEmployeeExport::collect($workspace->id, $employee->id);
    expect($data['profile']['name'])->toBe('Exported')
        ->and($data['telegram'][0])->toHaveKeys(['username', 'linked_at'])
        ->and(json_encode($data))->not->toContain('token')
        ->and($data['ai_queries'][0]['question'])->toBe('Wi-Fi?')
        ->and($data['incidents_reported'])->toHaveCount(1);

    Storage::disk('documents')->assertExists((string) $export->path);

    $url = URL::temporarySignedRoute('employees.exports.download', $export->expires_at, ['export' => $export->id], absolute: false);
    $this->get($url)->assertOk()->assertHeader('content-type', 'application/zip');
});

it('erases an offboarded employee after password confirmation and pseudonymizes records', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $employee = $this->telegramEmployee($workspace, 'Сергей Волков');
    $query = $this->aiQuery($workspace, $employee, ['question' => 'Personal question', 'answer' => 'Answer']);
    $incident = $this->incident($workspace, ['reporter_id' => $employee->id]);
    $question = $this->question($workspace, $employee, ['question' => 'Private']);

    $this->post(route('employees.erase', $employee))->assertRedirect(route('password.confirm'));
    $this->withSession(['auth.password_confirmed_at' => time()])->post(route('employees.erase', $employee))->assertSessionHas('error', __('errors.employees.erase_requires_offboarded'));

    WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $employee->id)->update(['status' => MemberStatus::Offboarded->value]);

    $this->withSession(['auth.password_confirmed_at' => time()])->post(route('employees.erase', $employee))->assertSessionHas('success');

    $employee->refresh();
    expect($employee->erased_at)->not->toBeNull()
        ->and($employee->displayName())->toBe('Former employee #'.$employee->id)
        ->and($query->refresh()->question)->toBeNull()
        ->and($question->refresh()->question)->toBe('[erased]')
        ->and($incident->refresh()->description)->toBe('Scratched a table')
        ->and(AiQuery::query()->count())->toBe(1)
        ->and(json_encode(AuditLog::query()->where('action', 'employee.erased')->sole()->metadata))->not->toContain('Сергей');

    $this->get(route('incidents.show', $incident))->assertInertia(fn ($page) => $page->where('incident.reporter.name', 'Former employee #'.$employee->id));
    $this->postJson(route('employees.reactivate', $employee))->assertStatus(422);

    [$admin] = $this->member(WorkspaceRole::Manager, $workspace);
    expect($admin->exists && $owner->exists)->toBeTrue();
});

it('only lets owners and admins erase', function (): void {
    [$manager, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $department = $this->department($workspace, 'mine');
    $this->inDepartment($manager, $department);
    $employee = $this->inDepartment($this->telegramEmployee($workspace, 'Gone', MemberStatus::Offboarded), $department);

    $this->withSession(['auth.password_confirmed_at' => time()])->post(route('employees.erase', $employee))->assertSessionHas('error');
    expect($employee->refresh()->erased_at)->toBeNull();
});

it('hides employees of other workspaces', function (): void {
    $this->signInMember(WorkspaceRole::Owner);
    [, $other] = $this->member(WorkspaceRole::Owner);
    $foreign = $this->telegramEmployee($other);

    $this->patch(route('employees.update', $foreign), ['name' => 'Hijack'])->assertNotFound();
    $this->postJson(route('employees.link', $foreign))->assertNotFound();
    expect($foreign->refresh()->name)->toBe('Айгуль');
});
