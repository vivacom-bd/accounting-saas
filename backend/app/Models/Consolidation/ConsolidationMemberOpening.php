<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * Opening translated equity of a foreign subsidiary held before the group's first run (D16.4): entered by the accountant
 * from the last consolidation done elsewhere, locked once a run using it is approved. Amounts are net (debit − credit)
 * minor units of the group's presentation currency.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $group_id
 * @property string $member_id
 * @property DateTimeImmutable $as_of
 * @property array<string, int> $equity group equity account id → translated amount at historical rates
 * @property int $translation_reserve
 * @property string $reason
 * @property string|null $locked_by_run_id
 * @property DateTimeImmutable|null $locked_at
 * @property int $lock_version
 * @property string|null $created_by
 * @property string|null $updated_by
 */
class ConsolidationMemberOpening extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_date', 'equity' => 'array', 'translation_reserve' => 'integer', 'locked_at' => 'immutable_datetime', 'lock_version' => 'integer',
        ];
    }
}
