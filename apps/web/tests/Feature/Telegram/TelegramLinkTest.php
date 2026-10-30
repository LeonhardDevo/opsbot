<?php

declare(strict_types=1);

use App\Domain\Opsbot\EmployeeNotice;
use App\Enums\BotStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\LegalAcceptance;
use App\Models\OutboxMessage;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Models\Workspace;

function issueTelegramToken(object $test, string $bot = 'test_bot'): string
{
    $response = $test->postJson(route('settings.integrations.telegram.link'))->assertOk();
    $url = $response->json('url');

    expect($url)->toMatch('#^https://t\.me/'.$bot.'\?start=[A-Za-z0-9]{40}$#');

    return substr($url, strpos($url, '=') + 1);
}

function companyBot(Workspace $workspace, BotStatus $status = BotStatus::Active, int $tgBotId = 7000000002): TelegramBot
{
    return TelegramBot::query()->create([
        'workspace_id' => $workspace->id,
        'tg_bot_id' => $tgBotId,
        'username' => 'company_'.$tgBotId.'_bot',
        'token' => $tgBotId.':TEST'.str_repeat('a', 30),
        'webhook_secret' => str_repeat('s', 32),
        'status' => $status,
        'mode' => 'fake',
    ]);
}

it('renders the integrations page with the platform bot when no company bot is active', function (): void {
    $this->signInMember(WorkspaceRole::Employee);

    $this->get(route('settings.integrations'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Integrations', false)
            ->where('telegram', ['linked' => false, 'username' => null, 'linked_at' => null, 'bot_username' => 'test_bot'])
            ->where('botUsername', 'test_bot'));
});

it('issues deep links for the active company bot and stores only a hash', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Manager);
    $bot = companyBot($workspace);

    $token = issueTelegramToken($this, 'company_7000000002_bot');

    $row = TelegramLinkToken::query()->sole();
    expect($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->user_id)->toBe($user->id)
        ->and($row->bot_key)->toBe($bot->key())
        ->and((int) round(now()->diffInMinutes($row->expires_at)))->toBe(15);
});

it('links a web user on the platform bot and requires the employee notice', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $token = issueTelegramToken($this);

    $this->internal('POST', '/internal/v1/telegram/link', [
        'bot_key' => 'platform',
        'token' => $token,
        'telegram_user_id' => 987654321,
        'chat_id' => 987654321,
        'username' => 'alex',
        'language_code' => 'ru',
    ])->assertOk()->assertExactJson([
        'user' => ['id' => $user->id, 'name' => $user->name, 'locale' => 'en'],
        'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
        'notice_required' => true,
    ]);

    $account = TelegramAccount::query()->sole();
    expect($account->user_id)->toBe($user->id)
        ->and($account->bot_key)->toBe('platform')
        ->and($account->workspace_id)->toBe($workspace->id)
        ->and(AuditLog::query()->where('action', 'telegram.linked')->where('workspace_id', $workspace->id)->exists())->toBeTrue()
        ->and(OutboxMessage::query()->topic('telegram.context.invalidate')->exists())->toBeTrue();

    $this->get(route('settings.integrations'))
        ->assertInertia(fn ($page) => $page->where('telegram.linked', true)->where('telegram.username', 'alex'));
});

it('rejects reused, expired, unknown and wrong-bot tokens', function (): void {
    [, $workspace] = $this->signInMember();
    $token = issueTelegramToken($this);
    $identity = ['bot_key' => 'platform', 'telegram_user_id' => 1, 'chat_id' => 1];

    $this->internal('POST', '/internal/v1/telegram/link', ['token' => $token, ...$identity, 'bot_key' => '77'])
        ->assertStatus(422)->assertJson(['error' => 'wrong_bot']);
    $this->internal('POST', '/internal/v1/telegram/link', ['token' => $token, ...$identity])->assertOk();
    $this->internal('POST', '/internal/v1/telegram/link', ['token' => $token, ...$identity])
        ->assertStatus(410)->assertJson(['error' => 'token_used']);

    $this->internal('POST', '/internal/v1/telegram/link', ['token' => str_repeat('x', 40), ...$identity])
        ->assertNotFound()->assertJson(['error' => 'invalid_token']);

    $expired = issueTelegramToken($this);
    $this->travel(16)->minutes();
    $this->internal('POST', '/internal/v1/telegram/link', ['token' => $expired, ...$identity])
        ->assertStatus(410)->assertJson(['error' => 'token_expired']);

    $this->internal('POST', '/internal/v1/telegram/link', ['token' => $token, ...$identity, 'bot_key' => 'not a key'])->assertStatus(422);
});

it('refuses to re-assign a Telegram identity that belongs to another user on the same bot', function (): void {
    [$other, $otherWorkspace] = $this->member();
    TelegramAccount::query()->create(['user_id' => $other->id, 'workspace_id' => $otherWorkspace->id, 'bot_key' => 'platform', 'telegram_user_id' => 42, 'chat_id' => 42, 'linked_at' => now()]);

    $this->signInMember();
    $token = issueTelegramToken($this);

    $this->internal('POST', '/internal/v1/telegram/link', ['bot_key' => 'platform', 'token' => $token, 'telegram_user_id' => 42, 'chat_id' => 42])
        ->assertStatus(409)->assertJson(['error' => 'conflict']);

    expect(TelegramAccount::query()->where('telegram_user_id', 42)->value('user_id'))->toBe($other->id);
});

it('allows the same telegram user on two different bots', function (): void {
    [$a, $workspaceA] = $this->member(WorkspaceRole::Employee);
    [$b, $workspaceB] = $this->member(WorkspaceRole::Employee);
    $botA = companyBot($workspaceA, tgBotId: 7000000011);
    $botB = companyBot($workspaceB, tgBotId: 7000000012);

    TelegramAccount::query()->create(['user_id' => $a->id, 'workspace_id' => $workspaceA->id, 'bot_key' => $botA->key(), 'telegram_bot_id' => $botA->id, 'telegram_user_id' => 5, 'chat_id' => 5, 'linked_at' => now()]);
    TelegramAccount::query()->create(['user_id' => $b->id, 'workspace_id' => $workspaceB->id, 'bot_key' => $botB->key(), 'telegram_bot_id' => $botB->id, 'telegram_user_id' => 5, 'chat_id' => 5, 'linked_at' => now()]);

    $this->internal('GET', '/internal/v1/telegram/context?bot_key='.$botA->key().'&telegram_user_id=5')->assertOk()->assertJsonPath('user.id', $a->id);
    $this->internal('GET', '/internal/v1/telegram/context?bot_key='.$botB->key().'&telegram_user_id=5')->assertOk()->assertJsonPath('user.id', $b->id);
});

it('builds the full telegram context by bot key or by workspace and user', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Employee, null, ['locale' => 'ru']);
    $workspace->forceFill(['timezone' => 'Europe/Moscow'])->save();
    $bot = companyBot($workspace);
    TelegramAccount::query()->create([
        'user_id' => $user->id, 'workspace_id' => $workspace->id, 'bot_key' => $bot->key(), 'telegram_bot_id' => $bot->id,
        'telegram_user_id' => 777, 'chat_id' => 778, 'username' => 'aigul', 'linked_at' => now(),
    ]);

    $expected = [
        'user' => ['id' => $user->id, 'name' => $user->name, 'locale' => 'ru', 'kind' => 'web'],
        'workspace' => ['id' => $workspace->id, 'plan' => 'free', 'timezone' => 'Europe/Moscow', 'subscription_state' => 'ok'],
        'role' => 'employee',
        'permissions' => WorkspaceRole::Employee->abilities(),
        'department_ids' => [],
        'privileged' => false,
        'telegram' => ['bot_key' => $bot->key(), 'user_id' => 777, 'chat_id' => 778, 'username' => 'aigul', 'blocked' => false],
        'bot' => ['key' => $bot->key(), 'username' => $bot->username],
        'limits' => ['ai_queries_exhausted' => false],
        'settings' => ['min_confidence' => 0.4, 'ai_external_allowed' => false],
    ];

    $response = $this->internal('GET', '/internal/v1/telegram/context?bot_key='.$bot->key().'&telegram_user_id=777')->assertOk()->assertJson($expected);
    expect($response->json('notice.version'))->toBe(app(EmployeeNotice::class)->version())
        ->and($response->json('notice.html'))->toContain('180');

    $this->internal('GET', '/internal/v1/telegram/context?workspace_id='.$workspace->id.'&user_id='.$user->id)->assertOk()->assertJson($expected);
    $this->internal('GET', '/internal/v1/telegram/context?bot_key=platform&telegram_user_id=777')->assertNotFound();
    $this->internal('GET', '/internal/v1/telegram/context')->assertStatus(422);

    $bot->forceFill(['status' => BotStatus::Revoked])->save();
    $this->internal('GET', '/internal/v1/telegram/context?bot_key='.$bot->key().'&telegram_user_id=777')->assertNotFound();
});

it('falls back to the platform bot for notifications when the company bot is not linked', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Manager);
    companyBot($workspace);
    TelegramAccount::query()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'bot_key' => 'platform', 'telegram_user_id' => 31, 'chat_id' => 31, 'linked_at' => now()]);

    $this->internal('GET', '/internal/v1/telegram/context?workspace_id='.$workspace->id.'&user_id='.$user->id)
        ->assertOk()
        ->assertJsonPath('telegram.bot_key', 'platform');
});

it('acknowledges the employee notice once per version', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Employee);
    TelegramAccount::query()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'bot_key' => 'platform', 'telegram_user_id' => 50, 'chat_id' => 50, 'linked_at' => now()]);
    $version = app(EmployeeNotice::class)->version();

    $this->internal('POST', '/internal/v1/telegram/notice/ack', ['bot_key' => 'platform', 'telegram_user_id' => 50, 'version' => $version])->assertNoContent();
    $this->internal('POST', '/internal/v1/telegram/notice/ack', ['bot_key' => 'platform', 'telegram_user_id' => 50, 'version' => $version])->assertNoContent();
    $this->internal('POST', '/internal/v1/telegram/notice/ack', ['bot_key' => 'platform', 'telegram_user_id' => 50, 'version' => '0.0'])->assertStatus(409);

    expect(LegalAcceptance::query()->sole()->only(['document', 'version', 'ip', 'user_agent']))->toBe([
        'document' => 'employee-notice', 'version' => $version, 'ip' => null, 'user_agent' => 'telegram',
    ])->and(AuditLog::query()->where('action', 'legal.employee_notice_acknowledged')->count())->toBe(1);

    $this->internal('GET', '/internal/v1/telegram/context?bot_key=platform&telegram_user_id=50')->assertJsonPath('notice', null);
});

it('unlinks through the internal endpoint and records blocked chats', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Employee);
    TelegramAccount::query()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'bot_key' => 'platform', 'telegram_user_id' => 60, 'chat_id' => 60, 'username' => 'eve', 'linked_at' => now()]);

    $this->internal('POST', '/internal/v1/telegram/chats/blocked', ['bot_key' => 'platform', 'telegram_user_id' => 60, 'blocked' => true])->assertNoContent();
    expect(TelegramAccount::query()->sole()->blocked_at)->not->toBeNull();

    $this->internal('POST', '/internal/v1/telegram/unlink', ['bot_key' => 'platform', 'telegram_user_id' => 60])->assertNoContent();

    $log = AuditLog::query()->where('action', 'telegram.unlinked')->sole();
    expect(TelegramAccount::query()->count())->toBe(0)
        ->and($log->user_agent)->toBe('telegram')
        ->and($log->ip)->toBeNull()
        ->and($log->metadata['source'])->toBe('bot')
        ->and(OutboxMessage::query()->topic('telegram.context.invalidate')->sole()->payload['keys'])->toBe([['bot_key' => 'platform', 'telegram_user_id' => 60]]);
});

it('unlinks from the integrations page and audits', function (): void {
    [$user, $workspace] = $this->signInMember();
    TelegramAccount::query()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'bot_key' => 'platform', 'telegram_user_id' => 9, 'chat_id' => 9, 'username' => 'bob', 'linked_at' => now()]);

    $this->delete(route('settings.integrations.telegram.unlink'))->assertSessionHas('success');

    expect(TelegramAccount::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'telegram.unlinked')->value('subject_label'))->toBe('@bob');
});

it('answers unknown actions with 404 and unlinked users with ok=false', function (): void {
    $this->internal('POST', '/internal/v1/telegram/actions', ['bot_key' => 'platform', 'type' => 'nope', 'telegram_user_id' => 1, 'chat_id' => 1])
        ->assertNotFound()->assertJson(['ok' => false, 'error' => 'unknown_action']);

    $this->internal('POST', '/internal/v1/telegram/actions', ['bot_key' => 'platform', 'type' => 'task_done', 'payload' => ['arg' => '1'], 'telegram_user_id' => 1, 'chat_id' => 1, 'locale' => 'ru'])
        ->assertNotFound()
        ->assertJson(['ok' => false, 'message' => __('errors.telegram.not_linked', [], 'ru'), 'alert' => true]);
});

it('hides linking when no bot is available', function (): void {
    config(['product.telegram.bot_username' => null]);
    $this->signInMember();

    $this->postJson(route('settings.integrations.telegram.link'))->assertStatus(422);
    expect(User::query()->count())->toBe(1);
});
