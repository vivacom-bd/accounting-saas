<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * A mirrored invoice (plan Task 12; 10_SaaS_Admin §3.5). Stripe invoices have a `stripe_id`; offline (manually raised)
 * ones do not and carry `source = 'offline'`. Money is integer minor units. Read-only from the admin side except the
 * refund/credit-note executors; the webhook job writes the Stripe rows.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $stripe_id
 * @property string $source
 * @property string|null $number
 * @property string $status
 * @property string $currency
 * @property int $subtotal
 * @property int $tax
 * @property int $total
 * @property int $amount_paid
 * @property int $amount_refunded
 * @property DateTimeImmutable|null $due_at
 * @property DateTimeImmutable|null $paid_at
 * @property DateTimeImmutable|null $period_start
 * @property DateTimeImmutable|null $period_end
 * @property array<int, array<string, mixed>> $lines
 * @property string|null $pdf_url
 * @property int $dunning_stage
 * @property DateTimeImmutable|null $created_at
 */
class StripeInvoice extends BaseModel
{
    protected $connection = 'pgsql_platform';

    protected $table = 'stripe_invoices';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subtotal' => 'integer', 'tax' => 'integer', 'total' => 'integer', 'amount_paid' => 'integer',
            'amount_refunded' => 'integer', 'dunning_stage' => 'integer', 'lines' => 'array',
            'due_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime', 'updated_from_event_at' => 'immutable_datetime',
        ];
    }
}
