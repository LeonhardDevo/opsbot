<?php

declare(strict_types=1);

namespace App\Http\Requests\Opsbot;

use Carbon\CarbonImmutable;

final class DueAt
{
    public static function parse(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $hasOffset = preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value) === 1;

        $parsed = $hasOffset ? CarbonImmutable::parse($value) : CarbonImmutable::parse($value, $timezone);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $parsed = $parsed->setTime(18, 0);
        }

        return $parsed->utc();
    }
}
