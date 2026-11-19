<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Contracts\ApprovalExecutor;
use App\Contracts\BillingGateway;
use App\Contracts\Clock;
use App\Domain\Admin\ApprovalKind;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminApproval;
use App\Models\Billing\StripeInvoice;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;

/**
 * Carries out an approved refund or credit note (plan Task 13). A Stripe invoice goes through the gateway; an offline
 * invoice (no `stripe_id`) is recorded locally by raising `amount_refunded`. Idempotent per approval: a retry after a
 * partial failure sees the same subject and does the gateway call once (the approval is executed in the same
 * transaction).
 */
final class RefundExecutor implements ApprovalExecutor
{
    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly AdminAuditWriter $audit,
        private readonly Clock $clock,
    ) {}

    public function kind(): ApprovalKind
    {
        return ApprovalKind::Refund;
    }

    public function execute(AdminActor $approver, AdminApproval $approval): void
    {
        $subject = $approval->subject;
        $invoiceId = $subject['invoice_id'] ?? null;
        $amount = $subject['amount'] ?? null;
        $reason = is_string($subject['reason'] ?? null) ? $subject['reason'] : $approval->reason;
        $type = ($subject['type'] ?? 'refund') === 'credit_note' ? 'credit_note' : 'refund';
        if (! is_string($invoiceId) || ! is_int($amount) || $amount < 1) {
            throw DomainRuleViolation::single('invoice_adjustment_invalid', 'The approval does not carry a valid invoice and amount.');
        }

        $invoice = StripeInvoice::query()->whereKey($invoiceId)->lockForUpdate()->first()
            ?? throw DomainRuleViolation::single('not_found', 'Invoice not found.');
        if ($invoice->amount_refunded + $amount > $invoice->total) {
            throw DomainRuleViolation::single('invoice_adjustment_invalid', 'This invoice has already been refunded to its total.');
        }

        $reference = null;
        if ($invoice->source === 'stripe' && $invoice->stripe_id !== null) {
            if (! $this->gateway->isConfigured()) {
                throw DomainRuleViolation::single('billing_not_configured', 'Stripe is not configured on this installation.');
            }
            $reference = $type === 'credit_note'
                ? $this->gateway->creditNote($invoice->stripe_id, $amount, $reason)
                : $this->gateway->refund($invoice->stripe_id, $amount, $reason);
        }

        $invoice->forceFill([
            'amount_refunded' => $invoice->amount_refunded + $amount,
            'updated_at' => $this->clock->now(),
        ])->save();

        $this->audit->record($approver, $type === 'credit_note' ? 'billing.credit_noted' : 'billing.refunded', $invoice->tenant_id, 'stripe_invoice', $invoice->id,
            ['amount_refunded' => $invoice->amount_refunded - $amount],
            ['amount_refunded' => $invoice->amount_refunded, 'amount' => $amount, 'reference' => $reference, 'type' => $type],
            $reason);
    }
}
