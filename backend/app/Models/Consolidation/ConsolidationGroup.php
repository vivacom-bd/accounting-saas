<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $presentation_currency
 * @property string $parent_company_id
 * @property int $ic_tolerance
 * @property string $status
 * @property int $lock_version
 * @property string|null $created_by
 */
class ConsolidationGroup extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ic_tolerance' => 'integer', 'lock_version' => 'integer'];
    }

    /** @return HasMany<ConsolidationMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ConsolidationMember::class, 'group_id');
    }

    /** @return HasMany<ConsolidationGroupAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(ConsolidationGroupAccount::class, 'group_id');
    }
}
