<?php

declare(strict_types=1);

namespace App\Queries\Admin\Billing;

use App\Http\Support\CursorPage;
use App\Models\Billing\StripeInvoice;
use Illuminate\Http\Request;

/**
 * `GET /api/admin/billing/invoices` (plan Task 13): every mirrored invoice, newest first. Filters: status, tenant,
 * currency, and a created-at date range. Money is integer minor units with its currency.
 */
final class ListInvoices
{
    /**
     * @param  array{status?: string|null, tenant?: string|null, currency?: string|null, from?: string|null, to?: string|null}  $filters
     */
    public function __invoke(Request $request, array $filters): CursorPage
    {
        $query = StripeInvoice::query();
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
        if (($filters['tenant'] ?? null) !== null) {
            $query->where('tenant_id', $filters['tenant']);
        }
        if (($filters['currency'] ?? null) !== null) {
            $query->where('currency', strtoupper((string) $filters['currency']));
        }
        if (($filters['from'] ?? null) !== null) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00+00');
        }
        if (($filters['to'] ?? null) !== null) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59+00');
        }

        return CursorPage::fetch($query, $request, 'created_at', true, 'timestamp');
    }
}
