<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Models\BaseModel;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's subscription (plan Task 11). At most one is live (not cancelled) per tenant. `collection` says who collects
 * the money: `stripe` (the gateway) or `offline` (invoiced manually; local state only). Once it exists it, not
 * `tenants.settings`, is the source of the tenant's plan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_version_id
 * @property string $collection
 * @property string $interval
 * @property string $currency
 * @property int|null $custom_amount
 * @property int $quantity
 * @property string $status
 * @property DateTimeImmutable|null $trial_ends_at
 * @property DateTimeImmutable|null $current_period_end
 * @property DateTimeImmutable|null $cancel_at
 * @property DateTimeImmutable|null $pause_until
 * @property string|null $coupon_id
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_customer_id
 * @property DateTimeImmutable|null $updated_from_event_at
 * @property DateTimeImmutable|null $created_at
 * @property DateTimeImmutable|null $updated_at
 */
class Subscription extends BaseModel
{
    protected $connection = 'pgsql_platform';

    protected $table = 'subscriptions';

    /** @return BelongsTo<PlanVersion, $this> */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class, 'plan_version_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'custom_amount' => 'integer', 'quantity' => 'integer', 'trial_ends_at' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime', 'cancel_at' => 'immutable_datetime', 'pause_until' => 'immutable_datetime',
            'updated_from_event_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }
}
