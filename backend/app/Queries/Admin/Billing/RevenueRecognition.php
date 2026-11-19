<?php

declare(strict_types=1);

namespace App\Queries\Admin\Billing;

use App\Domain\Billing\RevenueRecognition as Recogniser;
use App\Models\Billing\StripeInvoice;
use DateTimeImmutable;

/**
 * Deferred revenue for one calendar month (`GET /api/admin/billing/revenue-recognition?month=YYYY-MM`, plan Task 13).
 * For each paid invoice line, the amount recognised in that month is its straight-line daily share (see
 * Domain\Billing\RevenueRecognition), so an annual line spread over twelve months sums exactly to the line total.
 */
final class RevenueRecognition
{
    /**
     * @return list<array{invoice_id: string, number: string|null, tenant_id: string, currency: string, description: string, plan_code: string|null, recognised: int}>
     */
    public function __invoke(string $month): array
    {
        $rows = [];
        $invoices = StripeInvoice::query()->where('status', 'paid')->orderBy('created_at')->orderBy('id')->get();
        foreach ($invoices as $invoice) {
            foreach ($invoice->lines as $line) {
                $amount = (int) ($line['amount'] ?? 0);
                $start = $line['period_start'] ?? null;
                $end = $line['period_end'] ?? null;
                if ($amount === 0 || ! is_string($start) || ! is_string($end)) {
                    continue;
                }
                $recognised = Recogniser::inMonth($amount, new DateTimeImmutable($start), new DateTimeImmutable($end), $month);
                if ($recognised === 0) {
                    continue;
                }
                $rows[] = [
                    'invoice_id' => $invoice->id,
                    'number' => $invoice->number,
                    'tenant_id' => $invoice->tenant_id,
                    'currency' => $invoice->currency,
                    'description' => (string) ($line['description'] ?? ''),
                    'plan_code' => isset($line['plan_code']) && is_string($line['plan_code']) ? $line['plan_code'] : null,
                    'recognised' => $recognised,
                ];
            }
        }

        return $rows;
    }
}
