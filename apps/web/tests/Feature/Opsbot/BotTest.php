<?php

declare(strict_types=1);

use App\Domain\Opsbot\TelegramBotService;
use App\Enums\BotErrorCode;
use App\Enums\BotStatus;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\EmployeeLink;
use App\Models\OutboxMessage;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function botToken(int $id = 123456789): string
{
    return $id.':TEST'.str_repeat('a', 30);
}

/** @param array<string, mixed> $body */
function fakeVerify(array $body, int $status = 200): void
{
    Http::fake(['worker.test/internal/v1/telegram/bots/*/verify' => Http::response($body, $status)]);
}

it('connects a bot through the worker verify endpoint and never exposes the token', function (): void {
    fakeVerify(['ok' => true, 'tg_bot_id' => 123456789, 'username' => 'test_123456789_bot', 'first_name' => 'Test Bot 123456789', 'mode' => 'fake', 'can_join_groups' => false]);
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('bot.store'), ['token' => ' '.botToken().' '])->assertSessionHas('success', __('flash.bot.connected'));

    $bot = TelegramBot::query()->sole();
    expect($bot->status)->toBe(BotStatus::Active)
        ->and($bot->tg_bot_id)->toBe(123456789)
        ->and($bot->username)->toBe('test_123456789_bot')
        ->and($bot->mode)->toBe('fake')
        ->and($bot->token)->toBe(botToken())
        ->and(strlen((string) $bot->webhook_secret))->toBe(32)
        ->and($bot->connected_by)->toBe($owner->id)
        ->and(DB::table('telegram_bots')->value('token'))->not->toContain('TEST')
        ->and(AuditLog::query()->where('action', 'telegram_bot.connected')->sole()->metadata)->not->toHaveKey('token');

    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/internal/v1/telegram/bots/'.$bot->id.'/verify')
        && $request->header('X-Service') === ['web']
        && ! str_contains($request->body(), 'TEST'));

    $this->get(route('bot.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('bot/Index', false)
            ->where('bot.status', 'active')
            ->where('bot.token_hint', '…aaaa')
            ->missing('bot.token')
            ->where('platformBotUsername', 'test_bot')
            ->has('commands', 11)
            ->where('driver', 'auto'));

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('workspace.bot', ['key' => (string) $bot->id, 'username' => 'test_123456789_bot', 'status' => 'active']));
});

it('maps worker errors to bot states', function (array $body, BotStatus $status, string $error): void {
    fakeVerify($body);
    $this->signInMember(WorkspaceRole::Admin);

    $this->post(route('bot.store'), ['token' => botToken()])->assertSessionHas('error', __('errors.bot.'.$error));

    $bot = TelegramBot::query()->sole();
    expect($bot->status)->toBe($status)
        ->and($bot->last_error?->value)->toBe($error);
})->with([
    'invalid token' => [['ok' => false, 'error_code' => 'invalid_token'], BotStatus::InvalidToken, 'invalid_token'],
    'token mismatch' => [['ok' => false, 'error_code' => 'token_mismatch'], BotStatus::InvalidToken, 'token_mismatch'],
    'network' => [['ok' => false, 'error_code' => 'network'], BotStatus::Connecting, 'network'],
    'webhook failed' => [['ok' => false, 'error_code' => 'webhook_failed'], BotStatus::Connecting, 'webhook_failed'],
    'different bot id' => [['ok' => true, 'tg_bot_id' => 987, 'username' => 'x'], BotStatus::InvalidToken, 'token_mismatch'],
]);

it('treats an unreachable worker as a network error', function (): void {
    Http::fake(fn () => throw new ConnectionException('refused'));
    $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('bot.store'), ['token' => botToken()])->assertSessionHas('error', __('errors.bot.network'));
    expect(TelegramBot::query()->sole()->status)->toBe(BotStatus::Connecting);
});

it('validates the token format and refuses bots of other workspaces', function (): void {
    fakeVerify(['ok' => true, 'tg_bot_id' => 123456789, 'username' => 'a']);
    [, $other] = $this->member(WorkspaceRole::Owner);
    $this->companyBot($other, tgBotId: 123456789);
    $this->signInMember(WorkspaceRole::Owner);

    $this->post(route('bot.store'), ['token' => 'not-a-token'])->assertSessionHasErrors('token');
    $this->post(route('bot.store'), ['token' => botToken()])->assertSessionHas('error', __('errors.bot.already_connected'));

    expect(TelegramBot::query()->count())->toBe(1);
    Http::assertNothingSent();
});

it('rotates the token of the same bot and keeps links', function (): void {
    fakeVerify(['ok' => true, 'tg_bot_id' => 7000000001, 'username' => 'test_7000000001_bot', 'mode' => 'fake']);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $bot = $this->companyBot($workspace);
    $employee = $this->telegramEmployee($workspace);
    $this->linkTelegram($employee, $workspace, $bot->key(), 900);
    $oldSecret = $bot->webhook_secret;

    $this->patch(route('bot.token'), ['token' => '7000000001:TEST'.str_repeat('b', 30)])->assertSessionHas('success');

    $bot->refresh();
    expect($bot->token)->toEndWith('bbbb')
        ->and($bot->webhook_secret)->not->toBe($oldSecret)
        ->and($bot->status)->toBe(BotStatus::Active)
        ->and(TelegramAccount::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'telegram_bot.token_rotated')->exists())->toBeTrue();
});

it('drops links when the token belongs to a different bot', function (): void {
    fakeVerify(['ok' => true, 'tg_bot_id' => 7000000099, 'username' => 'new_bot', 'mode' => 'fake']);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $bot = $this->companyBot($workspace);
    $employee = $this->telegramEmployee($workspace);
    $this->linkTelegram($employee, $workspace, $bot->key(), 900);
    EmployeeLink::query()->create(['workspace_id' => $workspace->id, 'user_id' => $employee->id, 'bot_key' => $bot->key(), 'token_hash' => str_repeat('c', 64), 'expires_at' => now()->addDay()]);

    $this->patch(route('bot.token'), ['token' => botToken(7000000099)])->assertSessionHas('success');

    expect($bot->refresh()->tg_bot_id)->toBe(7000000099)
        ->and($bot->username)->toBe('new_bot')
        ->and(TelegramAccount::query()->count())->toBe(0)
        ->and(EmployeeLink::query()->whereNull('revoked_at')->count())->toBe(0)
        ->and(OutboxMessage::query()->topic('telegram.context.invalidate')->count())->toBe(1);

    $this->get(route('employees.index'))->assertInertia(fn ($page) => $page->where('pendingRelink', 1));
});

it('disconnects asynchronously and finalizes on the worker callback', function (): void {
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $bot = $this->companyBot($workspace);
    $employee = $this->telegramEmployee($workspace);
    $this->linkTelegram($employee, $workspace, $bot->key(), 900);

    $this->delete(route('bot.destroy'))->assertSessionHas('success');

    expect($bot->refresh()->status)->toBe(BotStatus::Disconnecting)
        ->and(OutboxMessage::query()->topic('telegram.bot.disconnect')->sole()->payload)->toEqual(['bot_key' => $bot->key(), 'telegram_bot_id' => $bot->id, 'workspace_id' => $workspace->id]);

    $this->internal('GET', '/internal/v1/telegram/bots/'.$bot->id.'/credentials')->assertOk()->assertJsonPath('token', $bot->token);

    $this->internal('POST', '/internal/v1/telegram/bots/'.$bot->id.'/status', ['status' => 'disconnected'])->assertNoContent();

    $bot->refresh();
    expect($bot->status)->toBe(BotStatus::Disconnected)
        ->and($bot->token)->toBeNull()
        ->and($bot->webhook_secret)->toBeNull()
        ->and(TelegramAccount::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'telegram_bot.disconnected')->exists())->toBeTrue();

    $this->internal('GET', '/internal/v1/telegram/bots/'.$bot->id.'/credentials')->assertStatus(410);
    $this->delete(route('bot.destroy'))->assertSessionHas('error', __('errors.bot.not_connected'));
});

it('reconnects a disconnected bot on the same row', function (): void {
    fakeVerify(['ok' => true, 'tg_bot_id' => 7000000001, 'username' => 'test_7000000001_bot', 'mode' => 'fake']);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $bot = $this->companyBot($workspace, BotStatus::Disconnected);

    $this->post(route('bot.store'), ['token' => botToken(7000000001)])->assertSessionHas('success');

    expect(TelegramBot::query()->sole()->id)->toBe($bot->id)
        ->and($bot->refresh()->status)->toBe(BotStatus::Active)
        ->and(AuditLog::query()->where('action', 'telegram_bot.connected')->exists())->toBeTrue();
});

it('marks revoked bots and notifies owners and admins', function (): void {
    [$owner, $workspace] = $this->member(WorkspaceRole::Owner);
    [$admin] = $this->member(WorkspaceRole::Admin, $workspace);
    $this->member(WorkspaceRole::Manager, $workspace);
    $bot = $this->companyBot($workspace);

    $this->internal('POST', '/internal/v1/telegram/bots/'.$bot->id.'/status', ['status' => 'revoked'])->assertNoContent();
    $this->internal('POST', '/internal/v1/telegram/bots/'.$bot->id.'/status', ['status' => 'revoked'])->assertNoContent();

    expect($bot->refresh()->status)->toBe(BotStatus::Revoked)
        ->and($bot->last_error)->toBe(BotErrorCode::Revoked)
        ->and(OutboxMessage::query()->topic('opsbot.bot.revoked')->pluck('payload')->map(fn ($p) => $p['recipient']['user_id'])->sort()->values()->all())->toBe([$owner->id, $admin->id])
        ->and(AuditLog::query()->where('action', 'telegram_bot.revoked')->count())->toBe(1);
});

it('records last seen and lists active bots for the worker', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    [, $second] = $this->member(WorkspaceRole::Owner);
    [, $third] = $this->member(WorkspaceRole::Owner);
    $active = $this->companyBot($workspace, tgBotId: 1001);
    $this->companyBot($second, BotStatus::Disconnected, 1002);
    $connecting = $this->companyBot($third, BotStatus::Connecting, 1003);

    $this->internal('POST', '/internal/v1/telegram/bots/'.$active->id.'/status', ['status' => 'active', 'seen_at' => '2026-10-22T10:00:00Z'])->assertNoContent();
    expect($active->refresh()->last_seen_at?->toIso8601String())->toBe('2026-10-22T10:00:00+00:00');

    $this->internal('GET', '/internal/v1/telegram/bots/active')
        ->assertOk()
        ->assertExactJson(['bots' => [
            ['bot_key' => $active->key(), 'status' => 'active', 'mode' => 'fake'],
            ['bot_key' => $connecting->key(), 'status' => 'connecting', 'mode' => 'fake'],
        ]]);

    $this->internal('GET', '/internal/v1/telegram/bots/active', service: 'ai')->assertForbidden();
});

it('finalizes stuck disconnects on the daily reconcile', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $bot = $this->companyBot($workspace, BotStatus::Disconnecting);
    TelegramBot::query()->whereKey($bot->id)->update(['updated_at' => now()->subHours(3)]);

    $this->internal('POST', '/internal/v1/opsbot/bots/reconcile', [])->assertOk()->assertExactJson(['finalized' => 1]);
    $this->internal('POST', '/internal/v1/opsbot/bots/reconcile', [])->assertOk()->assertExactJson(['finalized' => 0]);

    expect($bot->refresh()->status)->toBe(BotStatus::Disconnected);
});

it('sends a test message to the actor through the company bot', function (): void {
    [$owner, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $bot = $this->companyBot($workspace);

    $this->post(route('bot.test-message'))->assertSessionHas('error', __('errors.bot.actor_not_linked'));

    $this->linkTelegram($owner, $workspace, $bot->key(), 5);
    $this->post(route('bot.test-message'))->assertSessionHas('success');

    expect(OutboxMessage::query()->topic('telegram.notify')->sole()->payload)->toMatchArray([
        'workspace_id' => $workspace->id,
        'bot_key' => $bot->key(),
        'user_id' => $owner->id,
        'template' => 'opsbot.test_message',
    ]);
});

it('forbids managers and employees from the bot page', function (WorkspaceRole $role): void {
    $this->signInMember($role);

    $this->get(route('bot.index'))->assertForbidden();
    $this->post(route('bot.store'), ['token' => botToken()])->assertForbidden();
})->with([WorkspaceRole::Manager, WorkspaceRole::Employee]);

it('reports the delivery bot for invite links', function (): void {
    [, $workspace] = $this->member(WorkspaceRole::Owner);
    $service = app(TelegramBotService::class);

    expect($service->deliveryBot($workspace))->toBe(['key' => 'platform', 'username' => 'test_bot']);

    $bot = $this->companyBot($workspace);
    expect($service->deliveryBot($workspace))->toBe(['key' => $bot->key(), 'username' => $bot->username]);

    $bot->forceFill(['status' => BotStatus::Revoked])->save();
    config(['product.telegram.bot_username' => null]);
    expect($service->deliveryBot($workspace))->toBeNull()
        ->and(MemberStatus::Active->value)->toBe('active');
});
