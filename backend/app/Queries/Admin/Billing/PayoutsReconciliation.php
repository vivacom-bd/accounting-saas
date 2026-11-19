<?php

declare(strict_types=1);

namespace App\Queries\Admin\Billing;

use App\Models\Billing\StripePayout;

/**
 * Stripe payouts reconciled for a date range (`GET /api/admin/billing/payouts`, plan Task 13). One row per payout with
 * its arrival date and status; the CSV export (`/payouts.csv`) is built from the same rows.
 */
final class PayoutsReconciliation
{
    /**
     * @return list<array{stripe_id: string, amount: int, currency: string, arrival_date: string|null, status: string}>
     */
    public function __invoke(string $from, string $to): array
    {
        $rows = StripePayout::query()
            ->whereNotNull('arrival_date')
            ->whereBetween('arrival_date', [$from, $to])
            ->orderBy('arrival_date')->orderBy('stripe_id')
            ->get(['stripe_id', 'amount', 'currency', 'arrival_date', 'status']);

        return array_values($rows->map(fn (StripePayout $p): array => [
            'stripe_id' => $p->stripe_id,
            'amount' => (int) $p->amount,
            'currency' => $p->currency,
            'arrival_date' => $p->arrival_date === null ? null : (string) $p->arrival_date,
            'status' => $p->status,
        ])->all());
    }
}
