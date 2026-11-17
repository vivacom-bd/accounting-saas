<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * One MRR movement (new, expansion, contraction, churn, reactivation) in the minor units of its own currency.
 *
 * @property string $id
 * @property string $subscription_id
 * @property string $tenant_id
 * @property string $kind
 * @property string $currency
 * @property int $mrr_delta
 * @property DateTimeImmutable $occurred_at
 */
class SubscriptionEvent extends BaseModel
{
    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $table = 'subscription_events';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['mrr_delta' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }
}
