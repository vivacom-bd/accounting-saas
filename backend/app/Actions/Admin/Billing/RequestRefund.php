<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Actions\Admin\Approvals\RequestApproval;
use App\Domain\Admin\ApprovalKind;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminApproval;
use App\Models\Billing\StripeInvoice;
use App\Support\Admin\AdminActor;

/**
 * Asks for a refund or credit note on an invoice (plan Task 13). Only opens the two-person approval; the approver's
 * RefundExecutor performs it. The amount may not exceed what is still refundable (total minus what has been refunded).
 */
final class RequestRefund
{
    public function __construct(private readonly RequestApproval $request) {}

    public function handle(AdminActor $actor, string $invoiceId, int $amount, string $reason, string $type = 'refund'): AdminApproval
    {
        if (! in_array($type, ['refund', 'credit_note'], true)) {
            throw DomainRuleViolation::single('invoice_adjustment_invalid', 'Choose a refund or a credit note.');
        }
        $invoice = StripeInvoice::query()->find($invoiceId)
            ?? throw DomainRuleViolation::single('not_found', 'Invoice not found.');
        $refundable = $invoice->total - $invoice->amount_refunded;
        if ($amount < 1 || $amount > $refundable) {
            throw DomainRuleViolation::single('invoice_adjustment_invalid', 'The amount must be at least one minor unit and no more than the refundable balance.');
        }

        return $this->request->handle($actor, ApprovalKind::Refund, $invoice->tenant_id, [
            'invoice_id' => $invoice->id, 'amount' => $amount, 'reason' => $reason, 'type' => $type,
        ], $reason);
    }
}
