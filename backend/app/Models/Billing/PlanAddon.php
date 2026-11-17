<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * A purchasable add-on (extra company, user pack, storage, metered API). `price` is `{CUR: minor units}`.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property array<string, int> $price
 * @property string|null $unit_label
 * @property string|null $stripe_price_id
 * @property bool $is_active
 * @property DateTimeImmutable|null $created_at
 * @property DateTimeImmutable|null $updated_at
 */
class PlanAddon extends BaseModel
{
    protected $connection = 'pgsql_platform';

    protected $table = 'plan_addons';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['price' => 'array', 'is_active' => 'boolean', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
