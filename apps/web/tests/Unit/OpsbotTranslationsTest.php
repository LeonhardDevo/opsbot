<?php

declare(strict_types=1);

use App\Enums\ApprovalStatus;
use App\Enums\BotErrorCode;
use App\Enums\BotStatus;
use App\Enums\DismissReason;
use App\Enums\DocumentStatus;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Enums\LimitKey;
use App\Enums\QuestionReason;
use App\Enums\QuestionStatus;
use App\Enums\RoutingCategory;
use App\Enums\Severity;
use App\Enums\SubscriptionState;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Lang;

it('labels every enum case in both locales', function (string $prefix, array $values): void {
    foreach (['en', 'ru'] as $locale) {
        foreach ($values as $value) {
            expect(Lang::has($prefix.'.'.$value, $locale, false))->toBeTrue("missing {$locale}: {$prefix}.{$value}");
        }
    }
})->with([
    'roles' => ['roles', WorkspaceRole::values()],
    'incident category' => ['incidents.category', IncidentCategory::values()],
    'incident severity' => ['incidents.severity', Severity::values()],
    'incident status' => ['incidents.status', IncidentStatus::values()],
    'task status' => ['tasks.status', TaskStatus::values()],
    'question status' => ['questions.status', QuestionStatus::values()],
    'question reason' => ['questions.reason', QuestionReason::values()],
    'dismiss reason' => ['questions.dismiss_reason', DismissReason::values()],
    'document approval' => ['documents.approval', ApprovalStatus::values()],
    'document status' => ['documents.status', DocumentStatus::values()],
    'bot status' => ['bot.status', BotStatus::values()],
    'bot errors' => ['bot.errors', BotErrorCode::values()],
    'routing category' => ['routing.category', RoutingCategory::values()],
    'limit keys' => ['billing.limits', LimitKey::values()],
    'limit errors' => ['errors.limits', LimitKey::values()],
    'subscription state' => ['billing.subscription_state', SubscriptionState::values()],
]);

it('keeps the backend translation files of both locales in sync', function (string $file): void {
    $flatten = function (array $items, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($items as $key => $value) {
            $keys = is_array($value) ? [...$keys, ...$flatten($value, $prefix.$key.'.')] : [...$keys, $prefix.$key];
        }

        return $keys;
    };

    $en = $flatten(require lang_path("en/{$file}.php"));
    $ru = $flatten(require lang_path("ru/{$file}.php"));

    expect($ru)->toEqualCanonicalizing($en);
})->with(['opsbot', 'incidents', 'tasks', 'questions', 'employees', 'bot', 'roles', 'documents', 'routing', 'flash', 'errors', 'billing', 'audit']);

it('uses correct Russian plural forms', function (string $key, int $count, string $expected): void {
    expect(trans_choice($key, $count, ['count' => $count], 'ru'))->toBe($expected);
})->with([
    ['documents.count', 1, '1 документ'],
    ['documents.count', 2, '2 документа'],
    ['documents.count', 5, '5 документов'],
    ['documents.count', 11, '11 документов'],
    ['documents.count', 21, '21 документ'],
    ['documents.count', 22, '22 документа'],
    ['employees.count', 1, '1 сотрудник'],
    ['employees.count', 3, '3 сотрудника'],
    ['employees.count', 11, '11 сотрудников'],
    ['employees.count', 21, '21 сотрудник'],
    ['questions.count', 1, '1 вопрос'],
    ['questions.count', 2, '2 вопроса'],
    ['questions.count', 5, '5 вопросов'],
    ['questions.count', 22, '22 вопроса'],
    ['incidents.count', 1, '1 инцидент'],
    ['incidents.count', 2, '2 инцидента'],
    ['incidents.count', 5, '5 инцидентов'],
    ['incidents.count', 11, '11 инцидентов'],
    ['opsbot.counts.hours', 1, '1 час'],
    ['opsbot.counts.hours', 2, '2 часа'],
    ['opsbot.counts.hours', 5, '5 часов'],
    ['opsbot.counts.hours', 21, '21 час'],
    ['opsbot.counts.hours', 22, '22 часа'],
]);

it('uses English plural forms', function (): void {
    expect(trans_choice('documents.count', 1, ['count' => 1], 'en'))->toBe('1 document')
        ->and(trans_choice('documents.count', 2, ['count' => 2], 'en'))->toBe('2 documents')
        ->and(trans_choice('opsbot.counts.hours', 21, ['count' => 21], 'en'))->toBe('21 hours');
});

it('formats display numbers per locale', function (): void {
    expect(__('incidents.number', ['number' => 12], 'ru'))->toBe('№ 12')
        ->and(__('incidents.number', ['number' => 12], 'en'))->toBe('#12')
        ->and(__('tasks.number', ['number' => 34], 'ru'))->toBe('T-34')
        ->and(__('questions.number', ['number' => 15], 'en'))->toBe('Q-15');
});
