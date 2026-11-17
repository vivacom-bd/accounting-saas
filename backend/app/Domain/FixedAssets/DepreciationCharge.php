<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets;

/**
 * What depreciating one asset up to a period end comes to. `schedule` has one entry per month charged (units of production:
 * one entry for the whole run). `counterfactualAmount` is the charge the asset would have had without its impairment, used to
 * advance `counterfactualCarrying` (null when the asset has no impairment outstanding).
 */
final readonly class DepreciationCharge
{
    /**
     * @param  list<string>  $months  month ends the charge covers
     * @param  list<array{month_end: string, amount: int}>  $schedule
     */
    public function __construct(
        public array $months,
        public int $amount,
        public array $schedule,
        public int $units,
        public int $newMonthsCharged,
        public int $newCarrying,
        public ?string $through,
        public int $counterfactualAmount,
        public ?int $newCounterfactualCarrying,
    ) {}

    /** Periods charged beyond the one the run's own period accounts for (those ending before it starts): the catch-up shown in the preview. */
    public function catchUpMonths(string $periodEnd, ?DepreciationCalendar $calendar = null): int
    {
        $start = ($calendar ?? DepreciationCalendar::calendarMonths())->startOf($periodEnd);

        return count(array_filter($this->months, static fn (string $m): bool => $m < $start));
    }
}
