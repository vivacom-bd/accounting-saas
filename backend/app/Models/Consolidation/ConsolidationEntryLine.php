<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;

/**
 * @property string $id
 * @property string $entry_id
 * @property int $line_no
 * @property string $group_account_id
 * @property string|null $member_id
 * @property int $debit
 * @property int $credit
 * @property string|null $description
 */
class ConsolidationEntryLine extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['line_no' => 'integer', 'debit' => 'integer', 'credit' => 'integer'];
    }
}
