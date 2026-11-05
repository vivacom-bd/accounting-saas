<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use DateTimeImmutable;

/**
 * When a scheduled report is due (plan 3c.23), in friendly terms and not cron. Pure PHP; every date and time here is the
 * company's own (the caller converts "now" into the company's timezone).
 *
 * - daily: every day.
 * - weekly: on a weekday (1 = Monday ... 7 = Sunday).
 * - monthly: on a day of the month (1 to 28, so every month has it) or on the last day.
 * - quarterly: the same day rule, in January, April, July and October (calendar quarters; a company with another fiscal
 *   year start still gets calendar-quarter mail).
 *
 * A run is due on a date once the hour has been reached; a run that is picked up later the same day is still that day's run,
 * and a day that was missed completely is not made up (the scheduler records one run per due date, never a backlog).
 */
final class ScheduleRule
{
    public const array FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly'];

    /** The date this schedule is due on if `$now` is on or after its hour on a matching day; else null. */
    public static function dueOn(string $frequency, ?int $weekday, ?int $monthDay, bool $lastDay, int $hour, DateTimeImmutable $now): ?string
    {
        if ((int) $now->format('G') < $hour || ! self::matches($frequency, $weekday, $monthDay, $lastDay, $now)) {
            return null;
        }

        return $now->format('Y-m-d');
    }

    public static function matches(string $frequency, ?int $weekday, ?int $monthDay, bool $lastDay, DateTimeImmutable $date): bool
    {
        $dayOk = $lastDay ? $date->format('j') === $date->format('t') : (int) $date->format('j') === $monthDay;

        return match ($frequency) {
            'daily' => true,
            'weekly' => (int) $date->format('N') === $weekday,
            'monthly' => $dayOk,
            'quarterly' => in_array((int) $date->format('n'), [1, 4, 7, 10], true) && $dayOk,
            default => false,
        };
    }

    /** The next date and hour after `$now` (company local), searched over the next 400 days. */
    public static function next(string $frequency, ?int $weekday, ?int $monthDay, bool $lastDay, int $hour, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $start = $now->setTime(0, 0);
        for ($i = 0; $i <= 400; $i++) {
            $day = $start->modify("+{$i} days");
            if (! self::matches($frequency, $weekday, $monthDay, $lastDay, $day)) {
                continue;
            }
            $at = $day->setTime($hour, 0);
            if ($at > $now) {
                return $at;
            }
        }

        return null;
    }

    /** Plain words for the people who set it up: "Every Monday at 07:00". */
    public static function describe(string $frequency, ?int $weekday, ?int $monthDay, bool $lastDay, int $hour): string
    {
        $at = sprintf(' at %02d:00', $hour);
        $day = $lastDay ? 'the last day' : 'day '.$monthDay;

        return match ($frequency) {
            'daily' => 'Every day'.$at,
            'weekly' => 'Every '.['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$weekday ?? 1].$at,
            'monthly' => "Every month on {$day}".$at,
            'quarterly' => "Every quarter (January, April, July, October) on {$day}".$at,
            default => '',
        };
    }
}
