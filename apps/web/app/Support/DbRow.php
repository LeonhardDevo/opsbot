<?php

declare(strict_types=1);

namespace App\Support;

final class DbRow
{
    public static function get(mixed $row, string $key): mixed
    {
        if (! is_object($row)) {
            return null;
        }

        return get_object_vars($row)[$key] ?? null;
    }

    public static function int(mixed $row, string $key): int
    {
        $value = self::get($row, $key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function float(mixed $row, string $key): ?float
    {
        $value = self::get($row, $key);

        return is_numeric($value) ? (float) $value : null;
    }

    public static function string(mixed $row, string $key): string
    {
        $value = self::get($row, $key);

        return is_scalar($value) ? (string) $value : '';
    }
}
