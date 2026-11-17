<?php

declare(strict_types=1);

namespace App\Queries\Consolidation;

use App\Domain\Consolidation\Rounding;
use App\Models\Fx\ExchangeRate;
use App\Queries\Fx\FindExchangeRate;
use App\Support\Reports\Dates;
use DateTimeInterface;

/**
 * The default period average of a currency pair (13 §23 D16.4): the mean of the rate in force on each day of the window,
 * where the rate in force is the one a transaction of that day would have used (the latest on or before it, see
 * FindExchangeRate). The group overrides it where it does not approximate the transaction rates.
 *
 * Null when no rate is in force on the first day: a missing rate blocks the run rather than guessing.
 */
final class PeriodAverageRate
{
    public function __construct(private readonly FindExchangeRate $rates) {}

    /** @return array{rate: string, segments: int}|null */
    public function __invoke(string $companyId, string $from, string $to, string $windowFrom, string $windowTo): ?array
    {
        $changes = [];
        $rows = ExchangeRate::query()->where('company_id', $companyId)
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('from_currency', $from)->where('to_currency', $to))
                ->orWhere(fn ($p) => $p->where('from_currency', $to)->where('to_currency', $from)))
            ->whereIn('source', ['manual', 'provider'])->where('rate_date', '>', $windowFrom)->where('rate_date', '<=', $windowTo)
            ->orderBy('rate_date')->pluck('rate_date');
        foreach ($rows as $d) {
            $day = $d instanceof DateTimeInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10);
            $changes[$day] = true;
        }

        $starts = [$windowFrom, ...array_keys($changes)];
        $segments = [];
        foreach ($starts as $i => $day) {
            $quote = $this->rates->find($companyId, $from, $to, $day);
            if ($quote === null) {
                return null;
            }
            $segments[] = ['rate' => $quote->rate, 'days' => Dates::days($day, $starts[$i + 1] ?? Dates::after($windowTo))];
        }

        return ['rate' => Rounding::dailyMean($segments), 'segments' => count($segments)];
    }
}
