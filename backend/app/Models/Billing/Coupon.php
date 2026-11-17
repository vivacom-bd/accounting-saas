<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * A discount code: a percentage or a fixed amount in one currency, once, for N months or forever.
 *
 * @property string $id
 * @property string $code
 * @property string|null $stripe_coupon_id
 * @property int|null $percent
 * @property int|null $amount
 * @property string|null $currency
 * @property string $duration
 * @property int|null $duration_months
 * @property array<string, mixed> $restrictions
 * @property int|null $max_redemptions
 * @property int $redeemed_count
 * @property DateTimeImmutable|null $expires_at
 * @property string|null $partner_id
 * @property bool $is_active
 * @property DateTimeImmutable|null $created_at
 * @property DateTimeImmutable|null $updated_at
 */
class Coupon extends BaseModel
{
    protected $connection = 'pgsql_platform';

    protected $table = 'coupons';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'percent' => 'integer', 'amount' => 'integer', 'duration_months' => 'integer', 'restrictions' => 'array',
            'max_redemptions' => 'integer', 'redeemed_count' => 'integer', 'expires_at' => 'immutable_datetime',
            'is_active' => 'boolean', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }
}
