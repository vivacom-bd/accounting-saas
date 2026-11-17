<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $run_id
 * @property string|null $member_id
 * @property string $group_account_id
 * @property string $kind local|translated|adjustment|consolidated
 * @property string|null $source
 * @property string|null $entry_id
 * @property int $amount net, debit minus credit
 * @property-read ConsolidationGroupAccount|null $groupAccount
 */
class ConsolidationRunLine extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /** @return BelongsTo<ConsolidationGroupAccount, $this> */
    public function groupAccount(): BelongsTo
    {
        return $this->belongsTo(ConsolidationGroupAccount::class, 'group_account_id');
    }
}
