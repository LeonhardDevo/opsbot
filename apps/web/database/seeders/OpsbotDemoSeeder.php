<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Audit\Auditor;
use App\Domain\Opsbot\EmployeeNotice;
use App\Domain\Opsbot\IncidentService;
use App\Domain\Opsbot\StarterPacks;
use App\Domain\Opsbot\Support\WorkspaceCounters;
use App\Domain\Opsbot\TaskService;
use App\Enums\AiChannel;
use App\Enums\ApprovalStatus;
use App\Enums\BotStatus;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Enums\IncidentCategory;
use App\Enums\IncidentEventType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Enums\QuestionReason;
use App\Enums\QuestionStatus;
use App\Enums\RefusalReason;
use App\Enums\SubscriptionStatus;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Knowledge\DocumentService;
use App\Models\AiQuery;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\Document;
use App\Models\EmployeeLink;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\LegalAcceptance;
use App\Models\OpsSettings;
use App\Models\RoutingRule;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Models\UnansweredQuestion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OpsbotDemoSeeder extends Seeder
{
    public const string WORKSPACE_SLUG = 'clean-line';

    public const string BOT_TOKEN = '7000000001:TESTdemoSeedToken0000000000000000';

    public function __construct(
        private readonly Auditor $auditor,
        private readonly DocumentService $documents,
        private readonly StarterPacks $packs,
        private readonly WorkspaceCounters $counters,
        private readonly TaskService $tasks,
        private readonly EmployeeNotice $notice,
    ) {}

    public function run(): void
    {
        $admin = $this->webUser('admin@opsbot.test', 'Platform Admin', 'en');
        $admin->forceFill(['is_platform_admin' => true])->save();

        if (Workspace::query()->where('slug', self::WORKSPACE_SLUG)->exists()) {
            return;
        }

        $owner = $this->webUser('demo@opsbot.test', 'Марина Соколова', 'ru');
        $oleg = $this->webUser('manager@opsbot.test', 'Олег Петров', 'ru');
        $anna = $this->webUser('manager2@opsbot.test', 'Anna Smith', 'en');

        DB::transaction(function () use ($admin, $owner, $oleg, $anna): void {
            $workspace = Workspace::query()->create([
                'name' => 'Чистая линия',
                'slug' => self::WORKSPACE_SLUG,
                'timezone' => 'Europe/Moscow',
                'currency' => 'RUB',
                'country' => 'RU',
                'locale' => 'ru',
                'created_by' => $owner->id,
                'onboarding' => ['bot' => 'done', 'knowledge' => 'done', 'team' => 'done', 'finished_at' => now()->toIso8601String()],
            ]);

            OpsSettings::query()->create(['workspace_id' => $workspace->id]);

            Subscription::query()->create([
                'workspace_id' => $workspace->id,
                'gateway' => 'yookassa',
                'currency' => 'RUB',
                'plan' => 'pro',
                'status' => SubscriptionStatus::Trialing,
                'interval' => 'month',
                'trial_ends_at' => now()->addDays(14),
                'current_period_end' => now()->addDays(14),
            ]);

            $this->member($workspace, $owner, WorkspaceRole::Owner, MemberKind::Web, MemberStatus::Active);
            $this->member($workspace, $admin, WorkspaceRole::Admin, MemberKind::Web, MemberStatus::Active);
            $this->member($workspace, $oleg, WorkspaceRole::Manager, MemberKind::Web, MemberStatus::Active);
            $this->member($workspace, $anna, WorkspaceRole::Manager, MemberKind::Web, MemberStatus::Active);

            foreach ([$owner, $admin, $oleg, $anna] as $user) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            }

            $residential = Department::query()->create(['workspace_id' => $workspace->id, 'name' => 'Уборка квартир', 'code' => 'residential', 'manager_user_id' => $oleg->id]);
            $commercial = Department::query()->create(['workspace_id' => $workspace->id, 'name' => 'Уборка офисов', 'code' => 'commercial', 'manager_user_id' => $anna->id]);

            $this->department($workspace, $oleg, $residential, true);
            $this->department($workspace, $anna, $commercial, true);

            foreach ([[$residential->id, 'damage', $oleg->id], [$commercial->id, 'complaint', $anna->id], [null, 'injury', $owner->id]] as [$departmentId, $category, $managerId]) {
                RoutingRule::query()->create(['workspace_id' => $workspace->id, 'department_id' => $departmentId, 'category' => $category, 'manager_user_id' => $managerId]);
            }

            $bot = $this->bot($workspace, $owner);

            $aigul = $this->employee($workspace, 'Айгуль Сапарова', 'ru', $residential, MemberStatus::Active, $bot, 900000001);
            $ivan = $this->employee($workspace, 'Иван Кузнецов', 'ru', $residential, MemberStatus::Active, $bot, 900000002);
            $maria = $this->employee($workspace, 'Maria Lopez', 'en', $commercial, MemberStatus::Active, $bot, 900000003);
            $petr = $this->employee($workspace, 'Пётр Смирнов', 'ru', $commercial, MemberStatus::Invited, null, null);
            $sergey = $this->employee($workspace, 'Сергей Волков', 'ru', $residential, MemberStatus::Offboarded, null, null);

            EmployeeLink::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $petr->id,
                'bot_key' => $bot->key(),
                'token_hash' => EmployeeLink::hashToken('demo'.Str::random(36)),
                'expires_at' => now()->addDays(7),
                'created_by' => $owner->id,
            ]);

            $sergey->membershipIn($workspace)?->forceFill(['offboarded_at' => now()->subDays(20), 'offboarded_by' => $owner->id])->save();

            $documents = $this->knowledge($workspace, $owner);
            $employees = [$aigul, $ivan, $maria];

            $this->incidents($workspace, $employees, $oleg, $anna, $owner, $residential, $commercial);
            $this->taskList($workspace, $employees, $oleg, $anna, $residential, $commercial);
            $queries = $this->queries($workspace, [...$employees, $oleg, $anna], $documents, [$residential->id, $commercial->id]);
            $this->questions($workspace, $queries, $oleg, $anna, $owner);

            $this->auditor->record('workspace.created', $workspace, actor: $owner, workspace: $workspace, subjectLabel: $workspace->name, source: Auditor::SOURCE_SYSTEM);
            $this->auditor->record('telegram_bot.connected', $bot, metadata: ['tg_bot_id' => $bot->tg_bot_id, 'mode' => 'fake'], actor: $owner, workspace: $workspace, subjectLabel: '@'.$bot->username, source: Auditor::SOURCE_SYSTEM);
        });
    }

    private function webUser(string $email, string $name, string $locale): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->forceFill([
                'name' => $name,
                'password' => 'password',
                'locale' => $locale,
                'email_verified_at' => now(),
            ])->save();
        }

        return $user;
    }

    private function member(Workspace $workspace, User $user, WorkspaceRole $role, MemberKind $kind, MemberStatus $status): WorkspaceMembership
    {
        $membership = new WorkspaceMembership;
        $membership->forceFill([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'kind' => $kind,
            'status' => $status,
        ])->save();

        return $membership;
    }

    private function department(Workspace $workspace, User $user, Department $department, bool $primary): void
    {
        DepartmentMember::query()->create([
            'workspace_id' => $workspace->id,
            'department_id' => $department->id,
            'user_id' => $user->id,
            'is_primary' => $primary,
        ]);
    }

    private function bot(Workspace $workspace, User $owner): TelegramBot
    {
        $bot = new TelegramBot;
        $attributes = [
            'workspace_id' => $workspace->id,
            'tg_bot_id' => TelegramBot::botIdFromToken(self::BOT_TOKEN),
            'username' => 'test_7000000001_bot',
            'first_name' => 'Test Bot 7000000001',
            'token' => self::BOT_TOKEN,
            'webhook_secret' => 'demoWebhookSecret00000000000000ab',
            'status' => BotStatus::Active,
            'mode' => 'fake',
            'can_join_groups' => false,
            'connected_by' => $owner->id,
            'connected_at' => now()->subDays(30),
            'last_seen_at' => now(),
        ];

        if (! TelegramBot::query()->whereKey(1)->exists()) {
            $attributes['id'] = 1;
        }

        $bot->forceFill($attributes)->save();
        DB::statement("SELECT setval(pg_get_serial_sequence('telegram_bots', 'id'), (SELECT max(id) FROM telegram_bots))");

        return $bot;
    }

    private function employee(Workspace $workspace, string $name, string $locale, Department $department, MemberStatus $status, ?TelegramBot $bot, ?int $telegramUserId): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => null,
            'password' => null,
            'locale' => $locale,
            'current_workspace_id' => $workspace->id,
        ])->save();

        $this->member($workspace, $user, WorkspaceRole::Employee, MemberKind::TelegramOnly, $status);
        $this->department($workspace, $user, $department, true);

        if ($bot !== null && $telegramUserId !== null) {
            TelegramAccount::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'bot_key' => $bot->key(),
                'telegram_bot_id' => $bot->id,
                'telegram_user_id' => $telegramUserId,
                'chat_id' => $telegramUserId,
                'username' => null,
                'language_code' => $locale,
                'linked_at' => now()->subDays(25),
                'notice_version' => $this->notice->version(),
                'notice_acked_at' => now()->subDays(25),
            ]);

            LegalAcceptance::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'document' => EmployeeNotice::DOCUMENT,
                'version' => $this->notice->version(),
                'accepted_at' => now()->subDays(25),
                'ip' => null,
                'user_agent' => 'telegram',
                'locale' => $locale,
            ]);
        }

        return $user;
    }

    /** @return list<Document> */
    private function knowledge(Workspace $workspace, User $owner): array
    {
        $manifest = $this->packs->manifest('cleaning') ?? [];
        $departments = Department::query()->forWorkspace($workspace)->pluck('id', 'code');
        $approved = [];

        foreach ((array) ($manifest['documents'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (['ru', 'en'] as $language) {
                $codes = array_map('strval', (array) ($entry['department_codes'] ?? []));
                $approved[] = $this->documents->createFromContent($workspace, $owner, $this->packs->content('cleaning', $language, (string) $entry['file']), $entry['key'].'.'.$language.'.md', [
                    'title' => (string) ($entry['title'][$language] ?? $entry['key']),
                    'department_ids' => array_values(array_filter(array_map(fn (string $code): ?int => isset($departments[$code]) ? (int) $departments[$code] : null, $codes))),
                    'roles' => array_values(array_map('strval', (array) ($entry['roles'] ?? []))),
                    'tags' => array_values(array_map('strval', (array) ($entry['tags'] ?? []))),
                    'version' => (string) ($entry['version'] ?? '1.0'),
                    'language' => $language,
                    'source' => DocumentSource::StarterPack,
                    'source_ref' => 'cleaning:'.$entry['key'].':'.$language,
                    'approval_status' => ApprovalStatus::Approved,
                    'approved_by' => $owner->id,
                    'approved_at' => now()->subDays(29),
                    'effective_date' => '2026-01-01',
                    'review_due_at' => '2027-01-01',
                ], 'document.imported_starter_pack');
            }
        }

        $this->documents->createFromContent($workspace, $owner, "# Мойка окон на высоте\n\nЧерновик регламента: работы на высоте выполняются только вдвоём, со страховочным поясом и после инструктажа.\n", 'windows-at-height.md', [
            'title' => 'Мойка окон на высоте',
            'tags' => ['safety'],
            'version' => '0.1',
            'language' => 'ru',
        ], 'document.uploaded');

        $v1 = $this->documents->createFromContent($workspace, $owner, "# Стандарт уборки санузлов\n\nСанузел убирают сверху вниз: зеркала, раковина, унитаз, пол. Для унитаза используют отдельную ветошь красного цвета.\n", 'bathroom-standard-v1.md', [
            'title' => 'Стандарт уборки санузлов',
            'tags' => ['standard'],
            'version' => '1',
            'language' => 'ru',
            'approval_status' => ApprovalStatus::Approved,
            'approved_by' => $owner->id,
            'approved_at' => now()->subDays(29),
            'effective_date' => '2026-01-01',
            'review_due_at' => '2027-01-01',
        ], 'document.uploaded');
        $approved[] = $v1;

        $today = CarbonImmutable::now($workspace->zone());

        $this->documents->createFromContent($workspace, $owner, "# Новый стандарт уборки санузлов v2\n\nСанузел убирают сверху вниз. Для унитаза и пола используют одноразовые салфетки, ветошь больше не применяется.\n", 'bathroom-standard-v2.md', [
            'title' => 'Новый стандарт уборки санузлов v2',
            'tags' => ['standard'],
            'version' => '2',
            'language' => 'ru',
            'supersedes_document_id' => $v1->id,
            'approval_status' => ApprovalStatus::Approved,
            'approved_by' => $owner->id,
            'approved_at' => now(),
            'effective_date' => $today->addDays(30)->toDateString(),
            'review_due_at' => $today->addDays(395)->toDateString(),
        ], 'document.uploaded');

        foreach ($approved as $document) {
            $document->forceFill(['status' => DocumentStatus::Indexed, 'pages' => 1, 'pages_done' => 1, 'pages_total' => 1, 'chunks' => 4, 'indexed_at' => now()])->save();
        }

        return $approved;
    }

    /** @param  list<User>  $employees */
    private function incidents(Workspace $workspace, array $employees, User $oleg, User $anna, User $owner, Department $residential, Department $commercial): void
    {
        $plan = [
            [IncidentCategory::Damage, IncidentStatus::Open, $employees[0], $residential, $oleg, 'Поцарапала журнальный столик в гостиной у клиента на ул. Ленина, 5.', 'ул. Ленина, 5', 3],
            [IncidentCategory::Complaint, IncidentStatus::InProgress, $employees[2], $commercial, $anna, 'Client says the meeting room was not vacuumed yesterday.', 'Office Park B', 20],
            [IncidentCategory::NoAccess, IncidentStatus::Resolved, $employees[1], $residential, $oleg, 'Клиент не открывает дверь, ждал 20 минут, телефон не отвечает.', 'пр. Мира, 12', 50],
            [IncidentCategory::Injury, IncidentStatus::Open, $employees[1], $residential, $owner, 'Порезал палец о разбитый стакан на кухне, рана небольшая, обработал.', null, 2],
            [IncidentCategory::LostItem, IncidentStatus::Closed, $employees[0], $residential, $oleg, 'Клиент не может найти серьги после уборки спальни.', 'ул. Садовая, 8', 120],
            [IncidentCategory::Other, IncidentStatus::InProgress, $employees[2], $commercial, $anna, 'The cleaning cart wheel broke on the third floor.', null, 30],
        ];

        foreach ($plan as [$category, $status, $reporter, $department, $assignee, $description, $clientRef, $ageHours]) {
            $created = now()->subHours($ageHours);
            $incident = new Incident;
            $incident->forceFill([
                'workspace_id' => $workspace->id,
                'number' => $this->counters->next($workspace->id, WorkspaceCounters::INCIDENT),
                'category' => $category,
                'severity' => IncidentService::defaultSeverity($category),
                'status' => $status,
                'title' => IncidentService::title($category, $description, 'ru'),
                'description' => $description,
                'client_ref' => $clientRef,
                'department_id' => $department->id,
                'reporter_id' => $reporter->id,
                'assignee_id' => $assignee->id,
                'source' => IncidentSource::Bot,
                'resolved_at' => in_array($status, [IncidentStatus::Resolved, IncidentStatus::Closed], true) ? $created->addHours(6) : null,
                'closed_at' => $status === IncidentStatus::Closed ? $created->addHours(30) : null,
                'created_at' => $created,
                'updated_at' => $created->addHours(1),
            ])->save();

            IncidentEvent::query()->create([
                'workspace_id' => $workspace->id,
                'incident_id' => $incident->id,
                'actor_id' => $reporter->id,
                'type' => IncidentEventType::Created,
                'to_value' => IncidentStatus::Open->value,
            ]);

            if ($status !== IncidentStatus::Open) {
                IncidentEvent::query()->create([
                    'workspace_id' => $workspace->id,
                    'incident_id' => $incident->id,
                    'actor_id' => $assignee->id,
                    'type' => IncidentEventType::StatusChanged,
                    'from_value' => IncidentStatus::Open->value,
                    'to_value' => $status->value,
                ]);
            }
        }
    }

    /** @param  list<User>  $employees */
    private function taskList(Workspace $workspace, array $employees, User $oleg, User $anna, Department $residential, Department $commercial): void
    {
        $now = CarbonImmutable::now($workspace->zone());
        $plan = [
            ['Вернуть ключи клиента на ул. Ленина, 5', $employees[0], $oleg, $residential, $now->subDays(2)->setTime(18, 0), TaskStatus::Todo],
            ['Заказать новое колесо для тележки', $employees[1], $oleg, $residential, $now->subDay()->setTime(12, 0), TaskStatus::InProgress],
            ['Позвонить клиенту по поводу столика', $oleg, $oleg, $residential, $now->setTime(19, 0), TaskStatus::Todo],
            ['Check the meeting room after the complaint', $employees[2], $anna, $commercial, $now->addDays(1)->setTime(10, 0), TaskStatus::Todo],
            ['Prepare a deep-clean checklist for Office Park B', $anna, $anna, $commercial, $now->addDays(3)->setTime(15, 0), TaskStatus::InProgress],
            ['Пополнить запас перчаток', $employees[1], $oleg, $residential, $now->addDays(5)->setTime(9, 0), TaskStatus::Todo],
            ['Провести инструктаж по химии', $oleg, $oleg, $residential, $now->subDays(5)->setTime(11, 0), TaskStatus::Done],
            ['Update key handover log', $employees[2], $anna, $commercial, null, TaskStatus::Cancelled],
        ];

        foreach ($plan as [$title, $assignee, $creator, $department, $due, $status]) {
            $task = new Task;
            $task->forceFill([
                'workspace_id' => $workspace->id,
                'number' => $this->counters->next($workspace->id, WorkspaceCounters::TASK),
                'title' => $title,
                'status' => $status,
                'due_at' => $due?->utc(),
                'assignee_id' => $assignee->id,
                'created_by' => $creator->id,
                'department_id' => $department->id,
                'source' => TaskSource::Web,
                'completed_at' => $status === TaskStatus::Done ? $now->subDays(5)->utc() : null,
                'cancelled_at' => $status === TaskStatus::Cancelled ? $now->subDays(1)->utc() : null,
            ])->save();

            $this->tasks->replan($task, true);
        }
    }

    /**
     * @param  list<User>  $askers
     * @param  list<Document>  $documents
     * @param  list<int>  $departmentIds
     * @return list<AiQuery>
     */
    private function queries(Workspace $workspace, array $askers, array $documents, array $departmentIds): array
    {
        mt_srand(20261022);

        $answered = [
            'ru' => ['Что делать, если я поцарапала стол у клиента?', 'Сколько ждать, если клиент не открывает дверь?', 'Можно ли смешивать хлорку с другими средствами?', 'Куда вернуть ключи клиента после последнего визита?', 'До какой суммы менеджер может одобрить компенсацию без директора?'],
            'en' => ['What should I do if I scratched a client\'s table?', 'How long should I wait if nobody opens the door?', 'Can I mix bleach with other cleaning products?', 'Where do I return the client keys after the last visit?'],
        ];
        $refused = [
            'ru' => ['Какой пароль от гостевого Wi-Fi?', 'Сколько дней отпуска мне положено в году?', 'Какой пароль от Wi-Fi для гостей?', 'Как поменять пароль от почты?'],
            'en' => ['What is the Wi-Fi password for the guest network?', 'How many vacation days do I get per year?', 'How do I reset my email password?'],
        ];

        $queries = [];
        $now = CarbonImmutable::now();

        for ($i = 0; $i < 120; $i++) {
            $asker = $askers[mt_rand(0, count($askers) - 1)];
            $locale = $asker->locale === 'en' ? 'en' : 'ru';
            $isRefused = mt_rand(1, 100) <= 22;
            $pool = $isRefused ? $refused[$locale] : $answered[$locale];
            $question = $pool[mt_rand(0, count($pool) - 1)];
            $document = $documents[mt_rand(0, count($documents) - 1)];
            $createdAt = $now->subMinutes(mt_rand(10, 30 * 24 * 60 - 10));
            $membership = $asker->membershipIn($workspace);
            $departments = DepartmentMember::query()->where('workspace_id', $workspace->id)->where('user_id', $asker->id)->pluck('department_id')->map(fn (mixed $id): int => (int) $id)->all();

            $query = new AiQuery;
            $query->forceFill([
                'workspace_id' => $workspace->id,
                'user_id' => $asker->id,
                'channel' => mt_rand(1, 10) <= 8 ? AiChannel::Telegram : AiChannel::Web,
                'question' => $question,
                'answer' => $isRefused ? null : 'Ответ по документу «'.$document->title.'» [1].',
                'refused' => $isRefused,
                'confidence' => $isRefused ? mt_rand(5, 35) / 100 : mt_rand(55, 95) / 100,
                'citations' => $isRefused ? [] : [['document_id' => $document->id, 'chunk_id' => mt_rand(1, 400), 'page' => 1, 'title' => $document->title, 'version' => $document->version]],
                'tools_used' => $isRefused ? [] : ['search_documents'],
                'latency_ms' => mt_rand(350, 2600),
                'provider' => 'mock',
                'model' => 'mock-1',
                'answer_kind' => 'document',
                'bot_key' => '1',
                'conversation_id' => 'tg-1-'.$asker->id,
                'role_at_time' => $membership?->role->value,
                'department_ids_at_time' => $departments !== [] ? $departments : [$departmentIds[0]],
                'cited_document_versions' => $isRefused ? [] : [[
                    'document_id' => $document->id,
                    'title' => $document->title,
                    'version' => $document->version,
                    'approval_status' => 'approved',
                    'effective_date' => $document->effective_date?->toDateString(),
                ]],
                'refusal_reason' => $isRefused ? RefusalReason::LowConfidence : null,
                'created_at' => $createdAt,
            ])->save();

            $queries[] = $query;
        }

        return $queries;
    }

    /** @param  list<AiQuery>  $queries */
    private function questions(Workspace $workspace, array $queries, User $oleg, User $anna, User $owner): void
    {
        $refused = array_values(array_filter($queries, fn (AiQuery $query): bool => $query->refused));
        usort($refused, fn (AiQuery $a, AiQuery $b): int => $b->created_at <=> $a->created_at);

        $plan = [
            [QuestionStatus::Open, 2],
            [QuestionStatus::Open, 5],
            [QuestionStatus::Open, 10],
            [QuestionStatus::Open, 40],
            [QuestionStatus::Answered, 60],
            [QuestionStatus::ConvertedToDoc, 90],
        ];

        foreach ($plan as $index => [$status, $ageHours]) {
            $query = $refused[$index] ?? null;

            if ($query === null) {
                break;
            }

            $employee = User::query()->findOrFail($query->user_id);
            $department = DepartmentMember::query()->where('workspace_id', $workspace->id)->where('user_id', $employee->id)->where('is_primary', true)->value('department_id');
            $assignee = Department::query()->forWorkspace($workspace)->whereKey($department)->value('manager_user_id') ?? $owner->id;
            $created = now()->subHours($ageHours);

            $question = new UnansweredQuestion;
            $question->forceFill([
                'workspace_id' => $workspace->id,
                'number' => $this->counters->next($workspace->id, WorkspaceCounters::QUESTION),
                'ai_query_id' => $query->id,
                'question' => (string) $query->question,
                'employee_user_id' => $employee->id,
                'department_id' => $department,
                'assignee_user_id' => $assignee,
                'reason' => QuestionReason::LowConfidence,
                'status' => $status,
                'answer_text' => $status === QuestionStatus::Open ? null : 'Пароль от гостевой сети выдаёт администратор офиса, сотрудникам он не нужен.',
                'answered_by' => $status === QuestionStatus::Open ? null : ($assignee === $anna->id ? $anna->id : $oleg->id),
                'answered_at' => $status === QuestionStatus::Open ? null : $created->addHours(3),
                'created_at' => $created,
                'updated_at' => $created,
            ])->save();

            if ($status === QuestionStatus::ConvertedToDoc) {
                $document = $this->documents->createFromContent($workspace, $owner, '# FAQ: '.$question->question."\n\n## Вопрос\n\n".$question->question."\n\n## Ответ\n\n".$question->answer_text."\n", 'faq-'.$question->number.'.md', [
                    'title' => 'FAQ: '.Str::limit($question->question, 200, ''),
                    'department_ids' => $department !== null ? [(int) $department] : [],
                    'tags' => ['faq'],
                    'language' => 'ru',
                    'source' => DocumentSource::Faq,
                ], 'document.uploaded');
                $question->forceFill(['document_id' => $document->id])->save();
            }

            $query->forceFill(['escalated_question_id' => $question->id])->save();
        }
    }
}
