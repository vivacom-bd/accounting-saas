<?php

declare(strict_types=1);

namespace App\Services\FixedAssets;

use App\Domain\FixedAssets\DepreciationCalendar;
use App\Models\Fiscal\FiscalPeriod;

/** The periods a company depreciates in: its regular fiscal periods, never the adjustment period (plan 3c.15d). */
final class DepreciationCalendarLoader
{
    public function forCompany(string $companyId): DepreciationCalendar
    {
        $periods = [];
        foreach (FiscalPeriod::query()->where('company_id', $companyId)->where('kind', 'regular')->orderBy('starts_on')->get(['starts_on', 'ends_on']) as $p) {
            $periods[] = [$p->starts_on->format('Y-m-d'), $p->ends_on->format('Y-m-d')];
        }

        return new DepreciationCalendar($periods);
    }
}
