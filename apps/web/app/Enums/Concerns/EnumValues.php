<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

trait EnumValues
{
    abstract public static function labelPrefix(): string;

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        $key = static::labelPrefix().'.'.$this->value;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : str_replace('_', ' ', ucfirst($this->value));
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
