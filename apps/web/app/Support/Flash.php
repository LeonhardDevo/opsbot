<?php

declare(strict_types=1);

namespace App\Support;

final class Flash
{
    public const string SUCCESS = 'success';

    public const string ERROR = 'error';

    public const string LIMIT = 'limit';

    /** @param  array<string, mixed>  $replace */
    public static function success(string $translationKey, array $replace = []): void
    {
        session()->flash(self::SUCCESS, (string) __($translationKey, $replace));
    }

    /** @param  array<string, mixed>  $replace */
    public static function error(string $translationKey, array $replace = []): void
    {
        session()->flash(self::ERROR, (string) __($translationKey, $replace));
    }

    /** @param  array<string, mixed>  $replace */
    public static function successChoice(string $translationKey, int $count, array $replace = []): void
    {
        session()->flash(self::SUCCESS, trans_choice($translationKey, $count, ['count' => $count, ...$replace]));
    }

    public static function errorMessage(string $message): void
    {
        session()->flash(self::ERROR, $message);
    }

    public static function limit(string $limitKey, string $message): void
    {
        session()->flash(self::ERROR, $message);
        session()->flash(self::LIMIT, $limitKey);
    }
}
