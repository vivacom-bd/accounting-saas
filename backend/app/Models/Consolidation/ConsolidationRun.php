<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $group_id
 * @property DateTimeImmutable $period_from
 * @property DateTimeImmutable $period_to
 * @property string $currency
 * @property string $status computed|approved|reversed|void
 * @property list<array<string, mixed>> $warnings
 * @property array<string, mixed> $summary
 * @property int $lock_version
 * @property string|null $created_by
 * @property string|null $approved_by
 * @property DateTimeImmutable|null $approved_at
 * @property string|null $reversed_by
 * @property DateTimeImmutable|null $reversed_at
 * @property string|null $reason
 */
class ConsolidationRun extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date', 'period_to' => 'immutable_date', 'warnings' => 'array', 'summary' => 'array',
            'lock_version' => 'integer', 'approved_at' => 'immutable_datetime', 'reversed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ConsolidationRunMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ConsolidationRunMember::class, 'run_id');
    }

    /** @return HasMany<ConsolidationRunLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ConsolidationRunLine::class, 'run_id');
    }

    /** @return HasMany<ConsolidationEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(ConsolidationEntry::class, 'run_id');
    }
}
