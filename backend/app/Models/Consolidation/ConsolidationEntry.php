<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $group_id
 * @property string|null $run_id
 * @property string|null $number
 * @property string $status draft|posted|reversed|void
 * @property string $kind
 * @property DateTimeImmutable $entry_date
 * @property string|null $member_id
 * @property string|null $memo
 * @property array<string, mixed> $detail
 * @property int $total_debit
 * @property int $total_credit
 * @property string|null $reverses_entry_id
 * @property string|null $reversed_by_id
 * @property int $lock_version
 * @property string|null $created_by
 * @property string|null $approved_by
 * @property DateTimeImmutable|null $posted_at
 * @property-read ConsolidationGroup $group
 */
class ConsolidationEntry extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['entry_date' => 'immutable_date', 'detail' => 'array', 'total_debit' => 'integer', 'total_credit' => 'integer', 'lock_version' => 'integer', 'posted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ConsolidationGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ConsolidationGroup::class, 'group_id');
    }

    /** @return HasMany<ConsolidationEntryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ConsolidationEntryLine::class, 'entry_id')->orderBy('line_no');
    }
}
