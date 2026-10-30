<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Support;

final class DisplayNumber
{
    public static function incident(int $number, ?string $locale = null): string
    {
        return (string) __('incidents.number', ['number' => $number], $locale);
    }

    public static function task(int $number, ?string $locale = null): string
    {
        return (string) __('tasks.number', ['number' => $number], $locale);
    }

    public static function question(int $number, ?string $locale = null): string
    {
        return (string) __('questions.number', ['number' => $number], $locale);
    }
}
