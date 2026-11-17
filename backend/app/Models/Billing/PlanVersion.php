<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * One version of a plan. Immutable once `effective_from` has passed (trigger LG011); changing a plan means
 * publishing a new version. Subscribers keep the version they bought until migrated.
 *
 * @property string $id
 * @property string $plan_id
 * @property int $version
 * @property array<string, array{monthly: int|null, annual: int|null}> $prices
 * @property array<string, int|null> $limits
 * @property array<string, bool> $entitlements
 * @property int $trial_days
 * @property string|null $stripe_product_id
 * @property DateTimeImmutable $effective_from
 * @property string|null $created_by
 * @property DateTimeImmutable|null $created_at
 */
class PlanVersion extends BaseModel
{
    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $table = 'plan_versions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer', 'prices' => 'array', 'limits' => 'array', 'entitlements' => 'array', 'trial_days' => 'integer',
            'effective_from' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
        ];
    }
}
