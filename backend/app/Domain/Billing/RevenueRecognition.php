<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Straight-line revenue recognition for one invoice line (plan Task 13). The amount is spread by whole days over
 * `[period_start, period_end)` in integer minor units; the final month takes the rounding remainder, so the monthly
 * shares always add up to the line total exactly. Pure: callers pass the dates.
 */
final class RevenueRecognition
{
    /**
     * The share recognised in each calendar month (UTC) the line covers.
     *
     * @return array<string, int> month (YYYY-MM) => minor units
     */
    public static function split(int $amount, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $start = $start->setTimezone(new DateTimeZone('UTC'));
        $end = $end->setTimezone(new DateTimeZone('UTC'));
        if ($amount === 0) {
            return [];
        }

        $totalDays = (int) $start->setTime(0, 0)->diff($end->setTime(0, 0))->days;
        if ($totalDays <= 0) {
            return [$start->format('Y-m') => $amount];
        }

        $shares = [];
        $allocated = 0;
        $cursor = $start->modify('first day of this month')->setTime(0, 0);
        $lastKey = null;
        while ($cursor < $end) {
            $key = $cursor->format('Y-m');
            $monthEnd = $cursor->modify('+1 month');
            $overlapStart = $cursor > $start ? $cursor : $start->setTime(0, 0);
            $overlapEnd = $monthEnd < $end ? $monthEnd : $end->setTime(0, 0);
            $days = max(0, (int) $overlapStart->diff($overlapEnd)->days);
            $share = intdiv($amount * $days, $totalDays);
            $shares[$key] = $share;
            $allocated += $share;
            $lastKey = $key;
            $cursor = $monthEnd;
        }

        if ($lastKey !== null && $allocated !== $amount) {
            $shares[$lastKey] += $amount - $allocated;
        }

        return $shares;
    }

    /** The amount recognised in a given `YYYY-MM` month for a line, or 0 when the line does not cover it. */
    public static function inMonth(int $amount, DateTimeImmutable $start, DateTimeImmutable $end, string $month): int
    {
        return self::split($amount, $start, $end)[$month] ?? 0;
    }
}
