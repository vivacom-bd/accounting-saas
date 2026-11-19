<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Models\BaseModel;

/**
 * A mirrored Stripe payout (plan Task 12; 10_SaaS_Admin §3.5), for the reconciliation export. Written by the webhook
 * job; read-only for staff. Money is integer minor units.
 *
 * @property string $id
 * @property string $stripe_id
 * @property int $amount
 * @property string $currency
 * @property string|null $arrival_date
 * @property string $status
 */
class StripePayout extends BaseModel
{
    protected $connection = 'pgsql_platform';

    protected $table = 'stripe_payouts';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
