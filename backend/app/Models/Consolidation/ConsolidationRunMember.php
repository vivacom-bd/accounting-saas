<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * @property string $id
 * @property string $run_id
 * @property string $member_id
 * @property string $company_id
 * @property string $currency
 * @property DateTimeImmutable $window_from
 * @property int $ledger_version
 * @property string|null $closing_rate
 * @property string|null $average_rate
 * @property array<string, mixed> $rate_detail
 * @property array<string, mixed> $state
 */
class ConsolidationRunMember extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['window_from' => 'immutable_date', 'ledger_version' => 'integer', 'rate_detail' => 'array', 'state' => 'array'];
    }
}
