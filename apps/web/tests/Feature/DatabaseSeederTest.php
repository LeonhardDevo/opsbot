<?php

declare(strict_types=1);

use App\Billing\Markets\MarketResolver;
use App\Enums\BotStatus;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Enums\MemberStatus;
use App\Enums\QuestionStatus;
use App\Enums\WorkspaceRole;
use App\Legal\LegalAcceptances;
use App\Models\AiQuery;
use App\Models\Department;
use App\Models\Document;
use App\Models\Incident;
use App\Models\RoutingRule;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Models\UnansweredQuestion;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('documents');
});

it('seeds the deterministic bilingual demo workspace idempotently', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@opsbot.test')->sole();
    $owner = User::query()->where('email', 'demo@opsbot.test')->sole();
    $oleg = User::query()->where('email', 'manager@opsbot.test')->sole();
    $anna = User::query()->where('email', 'manager2@opsbot.test')->sole();
    $workspace = Workspace::query()->where('slug', 'clean-line')->sole();

    expect($admin->is_platform_admin)->toBeTrue()
        ->and($owner->name)->toBe('Марина Соколова')
        ->and(Hash::check('password', (string) $owner->password))->toBeTrue()
        ->and($owner->roleIn($workspace))->toBe(WorkspaceRole::Owner)
        ->and($oleg->roleIn($workspace))->toBe(WorkspaceRole::Manager)
        ->and($anna->roleIn($workspace))->toBe(WorkspaceRole::Manager)
        ->and($anna->locale)->toBe('en')
        ->and($workspace->only(['name', 'timezone', 'locale', 'country', 'currency']))->toBe([
            'name' => 'Чистая линия', 'timezone' => 'Europe/Moscow', 'locale' => 'ru', 'country' => 'RU', 'currency' => 'RUB',
        ])
        ->and($workspace->planKey())->toBe('pro')
        ->and(app(MarketResolver::class)->forWorkspace($workspace)->currency)->toBe('RUB')
        ->and($workspace->subscription?->gateway)->toBe('yookassa')
        ->and(app(LegalAcceptances::class)->pending($owner, $workspace, 'ru'))->toBe([])
        ->and(Workspace::query()->count())->toBe(1);

    $bot = TelegramBot::query()->sole();
    expect($bot->id)->toBe(1)
        ->and($bot->status)->toBe(BotStatus::Active)
        ->and($bot->username)->toBe('test_7000000001_bot')
        ->and($bot->token)->toStartWith('7000000001:TEST');

    expect(Department::query()->forWorkspace($workspace)->pluck('code')->sort()->values()->all())->toBe(['commercial', 'residential'])
        ->and(RoutingRule::query()->forWorkspace($workspace)->count())->toBe(3)
        ->and(TelegramAccount::query()->where('bot_key', '1')->pluck('telegram_user_id')->sort()->values()->all())->toBe([900000001, 900000002, 900000003])
        ->and(User::query()->where('name', 'Пётр Смирнов')->sole()->membershipIn($workspace)?->status)->toBe(MemberStatus::Invited)
        ->and(User::query()->where('name', 'Сергей Волков')->sole()->membershipIn($workspace)?->status)->toBe(MemberStatus::Offboarded);

    expect(Document::query()->forWorkspace($workspace)->where('source', 'starter_pack')->where('approval_status', 'approved')->count())->toBe(16)
        ->and(Document::query()->forWorkspace($workspace)->where('title', 'Мойка окон на высоте')->sole()->approval_status->value)->toBe('draft')
        ->and(Document::query()->forWorkspace($workspace)->where('title', 'Новый стандарт уборки санузлов v2')->sole()->supersedes_document_id)->not->toBeNull();

    $incidents = Incident::query()->forWorkspace($workspace)->get();
    expect($incidents)->toHaveCount(6)
        ->and($incidents->pluck('category')->map(fn (IncidentCategory $c): string => $c->value)->unique()->count())->toBe(6)
        ->and($incidents->pluck('status')->map(fn (IncidentStatus $s): string => $s->value)->unique()->sort()->values()->all())->toBe(['closed', 'in_progress', 'open', 'resolved']);

    $overdue = Task::query()->forWorkspace($workspace)->whereIn('status', ['todo', 'in_progress'])->where('due_at', '<', now())->count();
    expect(Task::query()->forWorkspace($workspace)->count())->toBe(8)
        ->and($overdue)->toBeGreaterThanOrEqual(2)
        ->and(TaskReminder::query()->forWorkspace($workspace)->where('status', 'planned')->count())->toBeGreaterThan(0);

    $questions = UnansweredQuestion::query()->forWorkspace($workspace)->get();
    expect($questions)->toHaveCount(6)
        ->and($questions->where('status', QuestionStatus::Open)->count())->toBe(4)
        ->and($questions->where('status', QuestionStatus::Answered)->count())->toBe(1)
        ->and($questions->where('status', QuestionStatus::ConvertedToDoc)->count())->toBe(1)
        ->and(AiQuery::query()->forWorkspace($workspace)->count())->toBe(120);
});

it('produces the same analytics input on every run', function (): void {
    $this->seed(DatabaseSeeder::class);
    $first = AiQuery::query()->orderBy('id')->get(['question', 'refused', 'latency_ms'])->toArray();

    $this->artisan('migrate:fresh');
    Storage::fake('documents');
    $this->seed(DatabaseSeeder::class);
    $second = AiQuery::query()->orderBy('id')->get(['question', 'refused', 'latency_ms'])->toArray();

    expect($second)->toBe($first);
});

it('lets the seeded demo owner sign in and reach the dashboard', function (): void {
    $this->seed(DatabaseSeeder::class);

    $this->post(route('login.store'), ['email' => 'demo@opsbot.test', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('workspace.name', 'Чистая линия')
        ->where('workspace.bot.status', 'active'));
});
