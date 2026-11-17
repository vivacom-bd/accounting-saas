<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets;

use DateTimeImmutable;

/**
 * The periods depreciation counts in: one regular fiscal period is one month of useful life (plan 3c.15d), whatever its length, so a
 * 4-4-5 year charges its 4, 4 and 5 week periods alike. Adjustment periods are not in it. Pure PHP on `Y-m-d` strings.
 *
 * Where no fiscal period covers a date (before the first year, after the last, or between years) a calendar month stands in, cut so it
 * never overlaps a real period. With no periods at all it is plain calendar months, which is what a monthly fiscal year is.
 */
final class DepreciationCalendar
{
    /** @param list<array{0: string, 1: string}> $periods regular periods as [starts_on, ends_on], any order */
    public function __construct(private readonly array $periods = []) {}

    public static function calendarMonths(): self
    {
        return new self;
    }

    /** @return array{0: string, 1: string} the [start, end] of the period $date falls in */
    public function periodOf(string $date): array
    {
        $before = null;
        $after = null;
        foreach ($this->periods as [$start, $end]) {
            if ($start <= $date && $date <= $end) {
                return [$start, $end];
            }
            if ($end < $date && ($before === null || $end > $before)) {
                $before = $end;
            }
            if ($start > $date && ($after === null || $start < $after)) {
                $after = $start;
            }
        }
        $start = Months::startOf($date);
        $end = Months::endOf($date);
        if ($before !== null && self::addDays($before, 1) > $start) {
            $start = self::addDays($before, 1);
        }
        if ($after !== null && self::addDays($after, -1) < $end) {
            $end = self::addDays($after, -1);
        }

        return [$start, $end];
    }

    public function endOf(string $date): string
    {
        return $this->periodOf($date)[1];
    }

    public function startOf(string $date): string
    {
        return $this->periodOf($date)[0];
    }

    /** The last day of the period before the one $date falls in. */
    public function endOfPrevious(string $date): string
    {
        return self::addDays($this->startOf($date), -1);
    }

    public function isPeriodEnd(string $date): bool
    {
        return $date === $this->endOf($date);
    }

    /**
     * Period ends from the period of $firstDate through the period of $lastDate, inclusive; empty when the first is after the last.
     *
     * @return list<string>
     */
    public function endsBetween(string $firstDate, string $lastDate): array
    {
        $out = [];
        $lastEnd = $this->endOf($lastDate);
        $end = $this->endOf($firstDate);
        while ($end <= $lastEnd) {
            $out[] = $end;
            $end = $this->endOf(self::addDays($end, 1));
        }

        return $out;
    }

    private static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '').$days.' days')->format('Y-m-d');
    }
}
