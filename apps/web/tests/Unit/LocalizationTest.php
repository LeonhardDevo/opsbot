<?php

declare(strict_types=1);

use App\Locale\Locales;

it('negotiates Accept-Language using q-values and primary tags', function (?string $header, ?string $expected): void {
    expect(Locales::negotiate($header))->toBe($expected);
})->with([
    'russian browser' => ['ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7', 'ru'],
    'english first' => ['en-GB,en;q=0.9,ru;q=0.8', 'en'],
    'q-values reorder' => ['de;q=0.9,ru;q=0.95,en;q=0.1', 'ru'],
    'only unsupported' => ['de-DE,fr;q=0.8', null],
    'wildcard only' => ['*', null],
    'zero quality ignored' => ['ru;q=0,en;q=0.5', 'en'],
    'empty' => ['', null],
    'missing' => [null, null],
    'underscore tag' => ['ru_RU', 'ru'],
]);

it('exposes native locale labels for switchers', function (): void {
    expect(Locales::options())->toBe([
        ['code' => 'en', 'label' => 'English'],
        ['code' => 'ru', 'label' => 'Русский'],
    ]);
});

it('pluralizes Russian nouns correctly (1 инцидент / 2 инцидента / 5 инцидентов)', function (int $count, string $expected): void {
    expect(trans_choice('billing.incidents_count', $count, [], 'ru'))->toBe($expected);
})->with([
    [1, '1 инцидент'],
    [2, '2 инцидента'],
    [4, '4 инцидента'],
    [5, '5 инцидентов'],
    [11, '11 инцидентов'],
    [12, '12 инцидентов'],
    [14, '14 инцидентов'],
    [21, '21 инцидент'],
    [22, '22 инцидента'],
    [25, '25 инцидентов'],
    [101, '101 инцидент'],
    [111, '111 инцидентов'],
    [0, '0 инцидентов'],
]);

it('pluralizes Russian documents and seats', function (): void {
    expect(trans_choice('billing.documents_count', 1, [], 'ru'))->toBe('1 документ')
        ->and(trans_choice('billing.documents_count', 3, [], 'ru'))->toBe('3 документа')
        ->and(trans_choice('billing.documents_count', 7, [], 'ru'))->toBe('7 документов')
        ->and(trans_choice('billing.seats_count', 2, [], 'ru'))->toBe('2 места')
        ->and(trans_choice('billing.seats_count', 15, [], 'ru'))->toBe('15 мест');
});

it('pluralizes English nouns', function (): void {
    expect(trans_choice('billing.incidents_count', 1, [], 'en'))->toBe('1 incident')
        ->and(trans_choice('billing.incidents_count', 2, [], 'en'))->toBe('2 incidents')
        ->and(trans_choice('billing.incidents_count', 0, [], 'en'))->toBe('0 incidents');
});

it('has the same translation keys in en and ru for every backend file', function (string $file): void {
    $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = [...$keys, ...(is_array($value) && $value !== [] ? $flatten($value, $path) : [$path])];
        }

        return $keys;
    };

    $en = $flatten(require lang_path("en/{$file}.php"));
    $ru = $flatten(require lang_path("ru/{$file}.php"));
    sort($en);
    sort($ru);

    expect(array_values(array_diff($en, $ru)))->toBe([], "Missing in ru/{$file}.php")
        ->and(array_values(array_diff($ru, $en)))->toBe([], "Missing in en/{$file}.php");
})->with(['auth', 'passwords', 'pagination', 'emails', 'notifications', 'billing', 'audit', 'legal']);

it('translates every standard validation rule into Russian', function (): void {
    $en = require lang_path('en/validation.php');
    $ru = require lang_path('ru/validation.php');

    $rules = array_diff(array_keys($en), ['custom', 'attributes']);

    expect(array_values(array_diff($rules, array_keys($ru))))->toBe([]);

    foreach ($rules as $rule) {
        if (is_array($en[$rule])) {
            expect(array_keys($ru[$rule]))->toEqualCanonicalizing(array_keys($en[$rule]));
        }
    }
});
