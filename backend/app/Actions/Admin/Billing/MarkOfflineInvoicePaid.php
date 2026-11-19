<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Actions\Platform\ChangeTenantLifecycle;
use App\Contracts\Clock;
use App\Domain\Platform\LifecycleState;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\StripeInvoice;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Marks a manually raised (offline) invoice paid (plan Task 13). Idempotent: an already paid invoice is left alone. A
 * past-due organisation comes back to active, the same as a Stripe `invoice.paid`, because collection succeeded.
 */
final class MarkOfflineInvoicePaid
{
    public function __construct(
        private readonly AdminAuditWriter $audit,
        private readonly Clock $clock,
        private readonly ChangeTenantLifecycle $lifecycle,
    ) {}

    public function handle(AdminActor $actor, string $invoiceId): StripeInvoice
    {
        return AdminTransaction::run(function () use ($actor, $invoiceId): StripeInvoice {
            $invoice = StripeInvoice::query()->whereKey($invoiceId)->lockForUpdate()->first()
                ?? throw DomainRuleViolation::single('not_found', 'Invoice not found.');
            if ($invoice->source !== 'offline') {
                throw DomainRuleViolation::single('invoice_not_offline', 'Only a manually raised invoice can be marked paid here.');
            }
            if ($invoice->status === 'paid') {
                return $invoice;
            }
            if (! in_array($invoice->status, ['open', 'draft'], true)) {
                throw DomainRuleViolation::single('invoice_not_payable', 'This invoice cannot be marked paid.');
            }

            $before = $invoice->status;
            $invoice->forceFill([
                'status' => 'paid', 'amount_paid' => $invoice->total, 'paid_at' => $this->clock->now(), 'updated_at' => $this->clock->now(),
            ])->save();
            $this->audit->record($actor, 'billing.invoice_paid', $invoice->tenant_id, 'stripe_invoice', $invoice->id, ['status' => $before], ['status' => 'paid']);

            $tenant = AdminTransaction::connection()->table('tenants')->where('id', $invoice->tenant_id)->first(['id', 'lifecycle']);
            if ($tenant !== null && $tenant->lifecycle === LifecycleState::PastDue->value) {
                $this->lifecycle->handle($actor, $invoice->tenant_id, LifecycleState::Active, 'billing.offline_invoice_paid');
            }

            return $invoice->refresh();
        });
    }
}
