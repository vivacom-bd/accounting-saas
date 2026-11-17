<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Models\BaseModel;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable plan (10_SaaS_Admin §3.3). Prices, limits and entitlements live on its immutable versions.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property int $sort
 * @property DateTimeImmutable|null $created_at
 * @property DateTimeImmutable|null $updated_at
 */
class Plan extends BaseModel
{
    protected $connection = 'pgsql_platform';

    protected $table = 'plans';

    /** @return HasMany<PlanVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class, 'plan_id')->orderBy('version');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
