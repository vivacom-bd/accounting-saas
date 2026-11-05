<?php

declare(strict_types=1);

namespace App\Queries\Tax;

use App\Domain\Tax\TaxReturnDiff;
use App\Models\Organisation\Company;
use App\Models\Tax\TaxPeriod;
use App\Queries\Reports\RunReport;

/**
 * The tax return of one tax period as it stands now, and its comparison with the frozen snapshot of a filed period (D15 item 13).
 * `generated_at` is dropped so a snapshot holds figures only.
 */
final class TaxPeriodReturn
{
    public function __construct(private readonly RunReport $report) {}

    /** @return array<string, mixed> */
    public function current(Company $company, TaxPeriod $period): array
    {
        $return = $this->report->taxReturnFor($company, ['from' => $period->starts_on->format('Y-m-d'), 'to' => $period->ends_on->format('Y-m-d')], $period->manual_amounts);
        unset($return['generated_at']);

        return $return;
    }

    /**
     * @return list<array{key: string, label: string, filed: int, current: int, difference: int}>
     */
    public function changesSinceFiling(Company $company, TaxPeriod $period): array
    {
        if ($period->status !== TaxPeriod::FILED || $period->return_snapshot === null) {
            return [];
        }

        return TaxReturnDiff::compare($period->return_snapshot, $this->current($company, $period));
    }
}
