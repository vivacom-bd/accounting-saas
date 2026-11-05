<?php

declare(strict_types=1);

namespace App\Domain\Consolidation;

/**
 * A member's dated ownership (D16.1): the parent's share in basis points. A row dated D applies to results AFTER D
 * (the transaction is measured at the close of D), so the share in force on a day is that of the last row dated before
 * the day. Pure.
 */
final class OwnershipHistory
{
    /**
     * @param  array<string, int>  $rows  effective_on (Y-m-d) → parent basis points
     */
    public function __construct(private readonly array $rows) {}

    /** The share governing results of $day: the last row dated strictly before it; null before the first row. */
    public function onDay(string $day): ?int
    {
        $found = null;
        $dates = array_keys($this->rows);
        sort($dates);
        foreach ($dates as $date) {
            if ($date < $day) {
                $found = $this->rows[$date];
            }
        }

        return $found;
    }

    /** The share after the close of $date (rows dated on or before it): the share at a run's end date. */
    public function after(string $date): ?int
    {
        $found = null;
        $dates = array_keys($this->rows);
        sort($dates);
        foreach ($dates as $d) {
            if ($d <= $date) {
                $found = $this->rows[$d];
            }
        }

        return $found;
    }
}
