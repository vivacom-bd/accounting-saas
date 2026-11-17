<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * An average rate the group sets for a currency and period where the default does not approximate the rates of the transactions (D16.4).
 *
 * @property string $id
 * @property string $group_id
 * @property string $currency
 * @property DateTimeImmutable $period_from
 * @property DateTimeImmutable $period_to
 * @property string $rate group-currency units per 1 unit of $currency
 * @property string $reason
 */
class ConsolidationRateOverride extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['period_from' => 'immutable_date', 'period_to' => 'immutable_date'];
    }
}
