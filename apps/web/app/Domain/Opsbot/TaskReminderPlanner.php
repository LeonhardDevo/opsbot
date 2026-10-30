<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Enums\ReminderKind;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class TaskReminderPlanner
{
    /** @return list<array{kind: ReminderKind, planned_at: CarbonImmutable}> */
    public static function plan(
        CarbonInterface $dueAt,
        string $timezone,
        string $quietStart,
        string $quietEnd,
        CarbonInterface $now,
    ): array {
        $due = CarbonImmutable::instance($dueAt)->setTimezone($timezone);
        $preDueHours = (int) config('opsbot.reminders.pre_due_hours', 2);
        $stepHours = (int) config('opsbot.reminders.overdue_step_hours', 24);
        $escalationDelay = (int) config('opsbot.reminders.escalation_delay_minutes', 1);

        $raw = [
            ReminderKind::PreDue->value => $due->subHours($preDueHours),
            ReminderKind::Due->value => $due,
            ReminderKind::Overdue1->value => self::addWallClockHours($due, $stepHours),
            ReminderKind::Overdue2->value => self::addWallClockHours($due, $stepHours * 2),
            ReminderKind::Overdue3->value => self::addWallClockHours($due, $stepHours * 3),
        ];

        $plan = [];
        $overdue3 = null;

        foreach ($raw as $kind => $instant) {
            if ($instant->lessThan($now)) {
                continue;
            }

            $planned = self::shiftOutOfQuietHours($instant, $quietStart, $quietEnd);
            $plan[] = ['kind' => ReminderKind::from($kind), 'planned_at' => $planned->utc()];

            if ($kind === ReminderKind::Overdue3->value) {
                $overdue3 = $planned;
            }
        }

        if ($overdue3 !== null) {
            $escalation = self::shiftOutOfQuietHours($overdue3->addMinutes($escalationDelay), $quietStart, $quietEnd);
            $plan[] = ['kind' => ReminderKind::Escalation, 'planned_at' => $escalation->utc()];
        }

        return $plan;
    }

    public static function shiftOutOfQuietHours(CarbonImmutable $local, string $quietStart, string $quietEnd): CarbonImmutable
    {
        if (! self::inQuietHours($local, $quietStart, $quietEnd)) {
            return $local;
        }

        [$endHour, $endMinute] = self::parse($quietEnd);
        $start = self::minutes($quietStart);
        $end = self::minutes($quietEnd);
        $secondsOfDay = $local->hour * 3600 + $local->minute * 60 + $local->second;

        $target = $local->setTime($endHour, $endMinute);

        if ($start > $end && $secondsOfDay >= $start * 60) {
            $target = $local->addDay()->setTime($endHour, $endMinute);
        }

        return $target;
    }

    public static function inQuietHours(CarbonImmutable $local, string $quietStart, string $quietEnd): bool
    {
        $start = self::minutes($quietStart) * 60;
        $end = self::minutes($quietEnd) * 60;

        if ($start === $end) {
            return false;
        }

        $time = $local->hour * 3600 + $local->minute * 60 + $local->second;

        return $start < $end
            ? $time >= $start && $time < $end
            : $time >= $start || $time < $end;
    }

    private static function addWallClockHours(CarbonImmutable $local, int $hours): CarbonImmutable
    {
        $days = intdiv($hours, 24);
        $rest = $hours % 24;

        return $local->addDays($days)->addHours($rest);
    }

    private static function minutes(string $time): int
    {
        [$hour, $minute] = self::parse($time);

        return $hour * 60 + $minute;
    }

    /** @return array{0: int, 1: int} */
    private static function parse(string $time): array
    {
        $parts = explode(':', $time);

        return [(int) $parts[0], (int) ($parts[1] ?? 0)];
    }
}
