<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;
use App\Models\Organisation\Company;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $group_id
 * @property string $company_id
 * @property string $role parent|subsidiary
 * @property DateTimeImmutable|null $acquisition_date
 * @property string $status
 * @property DateTimeImmutable|null $disposed_on
 */
class ConsolidationMember extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['acquisition_date' => 'immutable_date', 'disposed_on' => 'immutable_date'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<ConsolidationOwnership, $this> */
    public function ownerships(): HasMany
    {
        return $this->hasMany(ConsolidationOwnership::class, 'member_id')->orderBy('effective_on');
    }

    public function isParent(): bool
    {
        return $this->role === 'parent';
    }
}
