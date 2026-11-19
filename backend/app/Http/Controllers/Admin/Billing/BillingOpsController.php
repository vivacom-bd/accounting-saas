<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Actions\Admin\Billing\MarkOfflineInvoicePaid;
use App\Actions\Admin\Billing\RecordOfflineInvoice;
use App\Actions\Admin\Billing\RequestCreditNote;
use App\Actions\Admin\Billing\RequestRefund;
use App\Contracts\Clock;
use App\Http\Controllers\Admin\ApprovalsController;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Models\Billing\StripeInvoice;
use App\Queries\Admin\Billing\ListInvoices;
use App\Queries\Admin\Billing\PayoutsReconciliation;
use App\Queries\Admin\Billing\RevenueRecognition;
use App\Queries\Admin\Billing\TaxReport;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Billing operations (plan Task 13, 10 §3.5): the all-tenant invoice mirror, offline invoices, the refund and credit
 * note approval queue, payouts reconciliation and the tax and revenue-recognition exports. Money is integer minor
 * units with its currency; the CSV exports render them with the currency's own exponent.
 */
final class BillingOpsController extends Controller
{
    public function invoices(Request $request, ListInvoices $list): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,open,paid,void,uncollectible'],
            'tenant' => ['nullable', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $page = $list($request, $filters);

        return response()->json([
            'data' => $page->items->map(function ($i): array {
                $invoice = $i instanceof StripeInvoice ? $i : throw new LogicException;

                return [
                    'id' => $invoice->id, 'tenant_id' => $invoice->tenant_id, 'stripe_id' => $invoice->stripe_id, 'source' => $invoice->source,
                    'number' => $invoice->number, 'status' => $invoice->status, 'currency' => $invoice->currency,
                    'subtotal' => $invoice->subtotal, 'tax' => $invoice->tax, 'total' => $invoice->total,
                    'amount_paid' => $invoice->amount_paid, 'amount_refunded' => $invoice->amount_refunded,
                    'due_at' => $invoice->due_at?->format(DATE_RFC3339), 'paid_at' => $invoice->paid_at?->format(DATE_RFC3339),
                    'pdf_url' => $invoice->pdf_url, 'dunning_stage' => $invoice->dunning_stage,
                    'created_at' => $invoice->created_at?->format(DATE_RFC3339),
                ];
            })->all(),
            'meta' => ['next_cursor' => $page->nextCursor, 'prev_cursor' => $page->prevCursor, 'limit' => $page->limit],
        ]);
    }

    public function recordInvoice(Request $request, RecordOfflineInvoice $record): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'number' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3'],
            'subtotal' => ['required', 'integer', 'min:0'],
            'tax' => ['required', 'integer', 'min:0'],
            'due_at' => ['required', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after:period_start'],
        ]);

        $id = $record->handle(
            RequestAdminActor::from($request), $data['tenant_id'], $data['number'], $data['currency'],
            (int) $data['subtotal'], (int) $data['tax'], new DateTimeImmutable($data['due_at'].' UTC'),
            isset($data['period_start']) ? new DateTimeImmutable($data['period_start'].' UTC') : null,
            isset($data['period_end']) ? new DateTimeImmutable($data['period_end'].' UTC') : null,
        );

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function markPaid(Request $request, MarkOfflineInvoicePaid $mark, string $invoice): JsonResponse
    {
        $paid = $mark->handle(RequestAdminActor::from($request), $invoice);

        return response()->json(['data' => [
            'id' => $paid->id, 'status' => $paid->status, 'amount_paid' => $paid->amount_paid,
            'paid_at' => $paid->paid_at?->format(DATE_RFC3339),
        ]]);
    }

    public function refund(Request $request, RequestRefund $refund, Clock $clock, string $invoice): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']]);

        $approval = $refund->handle(RequestAdminActor::from($request), $invoice, (int) $data['amount'], $data['reason']);

        return response()->json(['data' => ApprovalsController::resource($approval, $clock)], 202);
    }

    public function creditNote(Request $request, RequestCreditNote $credit, Clock $clock, string $invoice): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']]);

        $approval = $credit->handle(RequestAdminActor::from($request), $invoice, (int) $data['amount'], $data['reason']);

        return response()->json(['data' => ApprovalsController::resource($approval, $clock)], 202);
    }

    public function taxReport(Request $request, TaxReport $report): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return response()->json(['data' => $report($data['from'], $data['to'])]);
    }

    public function revenueRecognition(Request $request, RevenueRecognition $recognition): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return response()->json(['data' => $recognition($data['month'])]);
    }

    public function revenueRecognitionCsv(Request $request, RevenueRecognition $recognition): StreamedResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $rows = $recognition($data['month']);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Invoice', 'Tenant', 'Currency', 'Description', 'Plan', 'Recognised']);
            foreach ($rows as $row) {
                fputcsv($out, [$row['number'], $row['tenant_id'], $row['currency'], $row['description'], $row['plan_code'], self::render($row['recognised'], $row['currency'])]);
            }
            fclose($out);
        }, "revenue-recognition-{$data['month']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function payouts(Request $request, PayoutsReconciliation $payouts): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return response()->json(['data' => $payouts($data['from'], $data['to'])]);
    }

    public function payoutsCsv(Request $request, PayoutsReconciliation $payouts): StreamedResponse
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $rows = $payouts($data['from'], $data['to']);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Stripe payout', 'Arrival date', 'Currency', 'Amount', 'Status']);
            foreach ($rows as $row) {
                fputcsv($out, [$row['stripe_id'], $row['arrival_date'], $row['currency'], self::render($row['amount'], $row['currency']), $row['status']]);
            }
            fclose($out);
        }, "stripe-payouts-{$data['from']}-{$data['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Minor units rendered with the currency's own exponent. Billing runs in USD, KES and EUR (all two decimals); a
     * zero-decimal currency (JPY) is the only exception Stripe payouts can carry.
     */
    private static function render(int $amount, string $currency): string
    {
        $exponent = strtoupper($currency) === 'JPY' ? 0 : 2;
        if ($exponent === 0) {
            return (string) $amount;
        }

        return number_format($amount / (10 ** $exponent), $exponent, '.', '');
    }
}
