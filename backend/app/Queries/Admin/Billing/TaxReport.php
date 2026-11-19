<?php

declare(strict_types=1);

namespace App\Queries\Admin\Billing;

use App\Support\Admin\AdminTransaction;
use stdClass;

/**
 * SaaS VAT/GST by customer country (`GET /api/admin/billing/tax-report`, plan Task 13). Rows are drawn from paid
 * invoices, grouped by the tenant's country, currency and calendar month: net (subtotal), tax and gross (total), all
 * integer minor units of the row's own currency.
 */
final class TaxReport
{
    /**
     * @return list<array{country: string|null, currency: string, month: string, net: int, tax: int, gross: int}>
     */
    public function __invoke(string $from, string $to): array
    {
        $rows = AdminTransaction::connection()->select(<<<'SQL'
            SELECT t.country AS country,
                   i.currency AS currency,
                   to_char(date_trunc('month', COALESCE(i.paid_at, i.created_at) AT TIME ZONE 'UTC'), 'YYYY-MM') AS month,
                   COALESCE(SUM(i.subtotal), 0)::bigint AS net,
                   COALESCE(SUM(i.tax), 0)::bigint AS tax,
                   COALESCE(SUM(i.total), 0)::bigint AS gross
            FROM stripe_invoices i
            LEFT JOIN tenants t ON t.id = i.tenant_id
            WHERE i.status = 'paid'
              AND COALESCE(i.paid_at, i.created_at) >= ?
              AND COALESCE(i.paid_at, i.created_at) <= ?
            GROUP BY t.country, i.currency, 3
            ORDER BY 3, t.country, i.currency
            SQL, [$from.' 00:00:00+00', $to.' 23:59:59+00']);

        return array_values(array_map(fn (stdClass $r): array => [
            'country' => $r->country === null ? null : (string) $r->country,
            'currency' => (string) $r->currency,
            'month' => (string) $r->month,
            'net' => (int) $r->net,
            'tax' => (int) $r->tax,
            'gross' => (int) $r->gross,
        ], $rows));
    }
}
