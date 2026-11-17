<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;

/**
 * @property string $id
 * @property string $group_id
 * @property string $member_id
 * @property string $company_id
 * @property string $account_id
 * @property string $group_account_id
 * @property string|null $ic_class balance|loan|income_expense|dividend, null when the account is not intercompany (D16.7)
 * @property string|null $counterparty_member_id the one member the whole account is with; null = from each line's contact
 * @property bool $net_investment a loan designated part of the net investment in a foreign operation (IAS 21.15, .32)
 */
class ConsolidationAccountMap extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['net_investment' => 'boolean'];
    }
}
