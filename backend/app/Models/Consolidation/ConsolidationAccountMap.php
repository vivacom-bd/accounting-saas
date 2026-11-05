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
 * @property bool $intercompany
 * @property string|null $counterparty_member_id
 * @property string|null $ic_class
 * @property string|null $flow_kind
 */
class ConsolidationAccountMap extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['intercompany' => 'boolean'];
    }
}
