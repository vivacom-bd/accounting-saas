<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Platform\LifecycleState;
use App\Models\Billing\Subscription;
use App\Notifications\Billing\PaymentFailed;
use App\Services\Billing\SubscriptionSupport;
use App\Services\Billing\TenantOwners;
use App\Support\Actor;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Applies one stored Stripe event to our state (plan Task 12). Runs in one platform transaction that also marks the
 * stored event processed, so a retry after a failure starts clean and a replay does nothing.
 *
 * Ordering (Review Focus 3): subscription state is written only when the event is not older than the newest event that
 * already wrote it (`subscriptions.updated_from_event_at`), so `invoice.paid` arriving before an older
 * `customer.subscription.created` still ends active. Invoices and payouts keep the same watermark.
 *
 * The tenant is found only through our own `subscriptions.stripe_subscription_id` / `stripe_customer_id` (set when the
 * subscription was created), never through Stripe metadata; an unknown customer is recorded and ignored.
 * A payment for a cancelled organisation never reactivates it: the event is flagged (`stripe_events.attention`) for staff.
 */
final class ApplyStripeEventPayload
{
    /** Stripe subscription status to ours; anything else (incomplete, ...) leaves our status alone. */
    private const array STATUS = [
        'trialing' => 'trialing', 'active' => 'active', 'past_due' => 'past_due', 'unpaid' => 'past_due',
        'canceled' => 'cancelled', 'paused' => 'paused',
    ];

    private const array INVOICE_STATUS = ['draft', 'open', 'paid', 'void', 'uncollectible'];

    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly SubscriptionSupport $support,
        private readonly TenantOwners $owners,
    ) {}

    public function __invoke(string $eventRowId): void
    {
        $db = AdminTransaction::connection();
        try {
            AdminTransaction::run(function () use ($db, $eventRowId): void {
                $row = $db->selectOne('SELECT id, payload, processed_at FROM stripe_events WHERE id = ? FOR UPDATE', [$eventRowId]);
                if ($row === null || $row->processed_at !== null) {
                    return;
                }
                /** @var array<string, mixed> $event */
                $event = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);
                $attention = $this->apply($event);
                $db->table('stripe_events')->where('id', $eventRowId)->update([
                    'processed_at' => $this->clock->now()->format('Y-m-d H:i:sP'), 'error' => null, 'attention' => $attention,
                ]);
            });
        } catch (Throwable $e) {
            $db->table('stripe_events')->where('id', $eventRowId)->update(['error' => mb_substr($e::class.': '.$e->getMessage(), 0, 2000)]);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return string|null a note for staff, or null
     */
    private function apply(array $event): ?string
    {
        $type = (string) ($event['type'] ?? '');
        $created = (int) ($event['created'] ?? 0);
        /** @var array<string, mixed> $object */
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];

        return match (true) {
            str_starts_with($type, 'customer.subscription.') => $this->subscription($type, $object, $created),
            in_array($type, ['invoice.created', 'invoice.finalized', 'invoice.paid', 'invoice.payment_failed', 'invoice.voided', 'invoice.marked_uncollectible'], true) => $this->invoice($type, $object, $created),
            $type === 'charge.refunded' => $this->refund($object),
            in_array($type, ['payout.paid', 'payout.failed'], true) => $this->payout($object, $created),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $o */
    private function subscription(string $type, array $o, int $created): null
    {
        $found = $this->resolve($o['id'] ?? null, $o['customer'] ?? null);
        if ($found === null) {
            return null;
        }
        $this->support->lockTenant($found->tenant_id);   // tenant first, then the subscription: the order every Action uses
        $sub = $this->fresh($found);
        if (! $this->ordered($sub, $created)) {
            return null;
        }

        $periodEnd = $o['current_period_end'] ?? $o['items']['data'][0]['current_period_end'] ?? null;
        $changes = [
            'status' => $type === 'customer.subscription.deleted' ? 'cancelled' : (self::STATUS[(string) ($o['status'] ?? '')] ?? $sub->status),
            'cancel_at' => $this->time($o['cancel_at'] ?? (! empty($o['cancel_at_period_end']) ? $periodEnd : null)),
        ];
        if (is_numeric($periodEnd)) {
            $changes['current_period_end'] = $this->time($periodEnd);
        }
        if (is_numeric($o['trial_end'] ?? null)) {
            $changes['trial_ends_at'] = $this->time($o['trial_end']);
        }
        if ($sub->stripe_subscription_id === null && is_string($o['id'] ?? null)) {
            $changes['stripe_subscription_id'] = $o['id'];
        }
        $this->write($sub, $changes, $created);

        return null;
    }

    /** @param  array<string, mixed>  $o */
    private function invoice(string $type, array $o, int $created): ?string
    {
        $stripeId = $o['id'] ?? null;
        if (! is_string($stripeId) || $stripeId === '') {
            throw new InvalidArgumentException('The invoice event carries no invoice id.');
        }
        $found = $this->resolve($o['subscription'] ?? null, $o['customer'] ?? null);
        if ($found === null) {
            return null;   // not one of ours: recorded, ignored
        }
        $tenantId = $found->tenant_id;
        $tenant = $this->support->lockTenant($tenantId);
        $this->mirrorInvoice($tenantId, $stripeId, $o, $type, $created);

        if ($type !== 'invoice.paid' && $type !== 'invoice.payment_failed') {
            return null;
        }
        $sub = $this->fresh($found);
        $state = LifecycleState::from($tenant->lifecycle);
        $gone = in_array($state, [LifecycleState::Cancelled, LifecycleState::Deleted], true) || $sub->status === 'cancelled';
        $at = new DateTimeImmutable('@'.$created);

        if ($type === 'invoice.paid') {
            if ($gone) {
                return "Payment received for invoice {$stripeId} but the organisation is {$state->value}; it was not reactivated. Staff to decide (reactivate, or refund).";
            }
            if (! $this->ordered($sub, $created)) {
                return null;
            }
            $this->write($sub, ['status' => 'active'], $created);
            $this->support->moveTenant(Actor::system($tenantId), $tenant, LifecycleState::Active, 'stripe.invoice_paid', $at);

            return null;
        }

        if ($gone || ! $this->ordered($sub, $created)) {
            return null;
        }
        $this->write($sub, ['status' => 'past_due'], $created);
        // Only the first failure starts the grace clock; later retries find the tenant already past due (or beyond).
        if ($state === LifecycleState::Active) {
            $this->support->moveTenant(Actor::system($tenantId), $tenant, LifecycleState::PastDue, 'stripe.payment_failed', $at);
        }
        $notice = new PaymentFailed($tenant->name, is_string($o['number'] ?? null) ? $o['number'] : null);
        AdminTransaction::connection()->afterCommit(fn () => $this->owners->notify($tenantId, $notice));

        return null;
    }

    /** @param  array<string, mixed>  $o */
    private function mirrorInvoice(string $tenantId, string $stripeId, array $o, string $type, int $created): void
    {
        $db = AdminTransaction::connection();
        $existing = $db->selectOne('SELECT id, updated_from_event_at FROM stripe_invoices WHERE stripe_id = ? FOR UPDATE', [$stripeId]);
        if ($existing !== null && $existing->updated_from_event_at !== null && strtotime((string) $existing->updated_from_event_at) > $created) {
            return;   // an older delivery never overwrites newer state
        }

        $status = match ($type) {
            'invoice.paid' => 'paid', 'invoice.voided' => 'void', 'invoice.marked_uncollectible' => 'uncollectible',
            default => in_array($o['status'] ?? null, self::INVOICE_STATUS, true) ? (string) $o['status'] : 'open',
        };
        $lines = [];
        foreach (is_array($o['lines']['data'] ?? null) ? $o['lines']['data'] : [] as $line) {
            $lines[] = [
                'description' => (string) ($line['description'] ?? ''),
                'amount' => (int) ($line['amount'] ?? 0),
                'period_start' => $this->time($line['period']['start'] ?? null),
                'period_end' => $this->time($line['period']['end'] ?? null),
                'plan_code' => $line['metadata']['plan_code'] ?? $line['price']['metadata']['plan_code'] ?? null,
            ];
        }
        $now = $this->clock->now()->format('Y-m-d H:i:sP');
        $data = [
            'number' => is_string($o['number'] ?? null) ? $o['number'] : null,
            'status' => $status,
            'currency' => strtoupper((string) ($o['currency'] ?? 'usd')),
            'subtotal' => (int) ($o['subtotal'] ?? 0),
            'tax' => $this->tax($o),
            'total' => (int) ($o['total'] ?? 0),
            'amount_paid' => (int) ($o['amount_paid'] ?? 0),
            'due_at' => $this->time($o['due_date'] ?? null),
            'paid_at' => $this->time($o['status_transitions']['paid_at'] ?? null),
            'period_start' => $this->time($o['period_start'] ?? null),
            'period_end' => $this->time($o['period_end'] ?? null),
            'lines' => json_encode($lines, JSON_THROW_ON_ERROR),
            'pdf_url' => is_string($o['invoice_pdf'] ?? null) ? $o['invoice_pdf'] : null,
            'updated_from_event_at' => $this->time($created),
            'synced_at' => $now,
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $db->table('stripe_invoices')->insert($data + ['id' => $this->ids->next(), 'tenant_id' => $tenantId, 'stripe_id' => $stripeId, 'source' => 'stripe', 'created_at' => $now]);
        } else {
            $db->table('stripe_invoices')->where('id', $existing->id)->update($data);   // amount_refunded is the refund event's
        }
    }

    /** @param  array<string, mixed>  $o */
    private function tax(array $o): int
    {
        if (is_numeric($o['tax'] ?? null)) {
            return (int) $o['tax'];
        }
        $sum = 0;
        foreach ([$o['total_taxes'] ?? [], $o['total_tax_amounts'] ?? []] as $taxes) {
            foreach (is_array($taxes) ? $taxes : [] as $tax) {
                $sum += (int) ($tax['amount'] ?? 0);
            }
            if ($sum !== 0) {
                break;
            }
        }

        return $sum;
    }

    /** @param  array<string, mixed>  $o */
    private function refund(array $o): null
    {
        $invoice = $o['invoice'] ?? null;
        if (! is_string($invoice) || $invoice === '' || ! is_numeric($o['amount_refunded'] ?? null)) {
            return null;
        }
        // The charge reports the running total; the largest seen wins, so a redelivered older event cannot lower it.
        AdminTransaction::connection()->update(
            'UPDATE stripe_invoices SET amount_refunded = GREATEST(amount_refunded, ?), updated_at = ? WHERE stripe_id = ?',
            [(int) $o['amount_refunded'], $this->clock->now()->format('Y-m-d H:i:sP'), $invoice],
        );

        return null;
    }

    /** @param  array<string, mixed>  $o */
    private function payout(array $o, int $created): null
    {
        $stripeId = $o['id'] ?? null;
        if (! is_string($stripeId) || $stripeId === '') {
            throw new InvalidArgumentException('The payout event carries no payout id.');
        }
        $db = AdminTransaction::connection();
        $existing = $db->selectOne('SELECT id, updated_from_event_at FROM stripe_payouts WHERE stripe_id = ? FOR UPDATE', [$stripeId]);
        if ($existing !== null && $existing->updated_from_event_at !== null && strtotime((string) $existing->updated_from_event_at) > $created) {
            return null;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:sP');
        $data = [
            'amount' => (int) ($o['amount'] ?? 0),
            'currency' => strtoupper((string) ($o['currency'] ?? 'usd')),
            'arrival_date' => is_numeric($o['arrival_date'] ?? null) ? gmdate('Y-m-d', (int) $o['arrival_date']) : null,
            'status' => substr((string) ($o['status'] ?? 'pending'), 0, 16),
            'updated_from_event_at' => $this->time($created),
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $db->table('stripe_payouts')->insert($data + ['id' => $this->ids->next(), 'stripe_id' => $stripeId, 'created_at' => $now]);
        } else {
            $db->table('stripe_payouts')->where('id', $existing->id)->update($data);
        }

        return null;
    }

    /** The subscription row for a Stripe subscription id, else for its customer; a live one before a cancelled one. */
    private function resolve(mixed $subscriptionId, mixed $customerId): ?Subscription
    {
        foreach ([['stripe_subscription_id', $subscriptionId], ['stripe_customer_id', $customerId]] as [$column, $value]) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            $sub = Subscription::query()->where($column, $value)->orderByRaw("status = 'cancelled'")->orderByDesc('created_at')->first();
            if ($sub !== null) {
                return $sub;
            }
        }

        return null;
    }

    private function fresh(Subscription $sub): Subscription
    {
        return Subscription::query()->whereKey($sub->id)->lockForUpdate()->firstOrFail();
    }

    private function ordered(Subscription $sub, int $created): bool
    {
        return $sub->updated_from_event_at === null || $created >= $sub->updated_from_event_at->getTimestamp();
    }

    /** @param  array<string, mixed>  $changes */
    private function write(Subscription $sub, array $changes, int $created): void
    {
        $before = $this->support->mrrNow($sub);
        $sub->forceFill($changes + ['updated_from_event_at' => $this->time($created)])->save();
        $this->support->recordMovement($sub, $before, $this->support->mrrNow($sub), new DateTimeImmutable('@'.$created));
    }

    private function time(mixed $epoch): ?string
    {
        return is_numeric($epoch) ? (new DateTimeImmutable('@'.(int) $epoch))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP') : null;
    }
}
