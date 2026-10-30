<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<list<int>, iterable<int|string>|string|null> */
final class PgIntegerArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<int>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return self::parse($value);
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return self::format(self::parse($value));
    }

    /** @return list<int> */
    public static function parse(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $inner = trim($value, '{}');

            if ($inner === '') {
                return [];
            }

            $value = explode(',', $inner);
        }

        if (! is_iterable($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @param  list<int>  $ids */
    public static function format(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }
}
