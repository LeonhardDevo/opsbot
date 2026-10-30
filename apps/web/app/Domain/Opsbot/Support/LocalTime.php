<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class LocalTime
{
    private const array RU_MONTHS = ['янв.', 'февр.', 'мар.', 'апр.', 'мая', 'июн.', 'июл.', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.'];

    public static function dateTime(?CarbonInterface $instant, string $timezone, string $locale): ?string
    {
        if ($instant === null) {
            return null;
        }

        $local = CarbonImmutable::instance($instant)->setTimezone($timezone);

        if ($locale === 'ru') {
            return $local->day.' '.self::RU_MONTHS[$local->month - 1].', '.$local->format('H:i');
        }

        return $local->format('M j, g:i A');
    }

    public static function date(?CarbonInterface $date, string $locale): ?string
    {
        if ($date === null) {
            return null;
        }

        $local = CarbonImmutable::instance($date);

        if ($locale === 'ru') {
            return $local->day.' '.self::RU_MONTHS[$local->month - 1].' '.$local->year;
        }

        return $local->format('M j, Y');
    }

    public static function ageHours(CarbonInterface $since, ?CarbonInterface $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return max(0, intdiv($now->getTimestamp() - $since->getTimestamp(), 3600));
    }
}
