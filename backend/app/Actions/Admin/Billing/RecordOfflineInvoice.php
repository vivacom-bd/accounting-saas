<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Records an invoice raised by hand for an offline (invoiced) subscription (plan Task 13). It lives in the same
 * `stripe_invoices` mirror with `source = offline` and no Stripe id, so every report reads one table.
 */
final class RecordOfflineInvoice
{
    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly AdminAuditWriter $audit,
    ) {}

    /** Returns the new invoice id. */
    public function handle(
        AdminActor $actor,
        string $tenantId,
        string $number,
        string $currency,
        int $subtotal,
        int $tax,
        DateTimeImmutable $dueAt,
        ?DateTimeImmutable $periodStart = null,
        ?DateTimeImmutable $periodEnd = null,
    ): string {
        if ($subtotal < 0 || $tax < 0) {
            throw DomainRuleViolation::single('invoice_amount_invalid', 'Amounts cannot be negative.');
        }
        $db = DB::connection(AdminTransaction::CONNECTION);
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        return AdminTransaction::run(function () use ($db, $actor, $tenantId, $number, $currency, $subtotal, $tax, $dueAt, $periodStart, $periodEnd, $now): string {
            if (! $db->table('tenants')->where('id', $tenantId)->exists()) {
                throw DomainRuleViolation::single('not_found', 'Organisation not found.');
            }
            $id = $this->ids->next();
            $db->table('stripe_invoices')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'stripe_id' => null, 'source' => 'offline', 'number' => $number,
                'status' => 'open', 'currency' => strtoupper($currency), 'subtotal' => $subtotal, 'tax' => $tax,
                'total' => $subtotal + $tax, 'amount_paid' => 0, 'amount_refunded' => 0,
                'due_at' => $dueAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
                'period_start' => $periodStart?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
                'period_end' => $periodEnd?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
                'lines' => '[]', 'created_at' => $now->format('Y-m-d H:i:sP'), 'updated_at' => $now->format('Y-m-d H:i:sP'),
            ]);
            $this->audit->record($actor, 'billing.invoice_recorded', $tenantId, 'stripe_invoice', $id, null, [
                'number' => $number, 'currency' => strtoupper($currency), 'subtotal' => $subtotal, 'tax' => $tax,
            ]);

            return $id;
        });
    }
}
