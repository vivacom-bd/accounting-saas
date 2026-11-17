<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\BillingGateway;
use App\Domain\Shared\DomainRuleViolation;
use App\Services\Billing\Dto\GatewayInvoicePreview;
use App\Services\Billing\Dto\GatewaySubscription;
use Closure;
use DateTimeImmutable;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Stripe\Subscription;

/**
 * Stripe behind the BillingGateway (plan G2, T5). It is written against stripe-php and its request mapping is covered by
 * a scripted HTTP client (tests/Unit/Billing/StripeBillingGatewayTest); it has NOT been exercised against Stripe itself,
 * because no keys exist. Treat parameter names such as the credit-note shape as unverified until a test-mode run.
 *
 * Stripe's period dates live on the subscription item in current API versions; this reads them from there. A paused
 * collection is reported as `paused` (Stripe keeps the subscription `active`). The secret never leaves the StripeClient;
 * errors are reduced to Stripe's own human message.
 */
final class StripeBillingGateway implements BillingGateway
{
    private const array STATUS = [
        'trialing' => 'trialing', 'active' => 'active', 'past_due' => 'past_due', 'unpaid' => 'past_due', 'incomplete' => 'past_due',
        'paused' => 'paused', 'canceled' => 'cancelled', 'incomplete_expired' => 'cancelled',
    ];

    public function __construct(private readonly StripeClient $stripe) {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function ensureCustomer(string $tenantId, string $name, string $email): string
    {
        return $this->call(function () use ($tenantId, $name, $email): string {
            $key = str_replace(["'", '\\'], '', $tenantId);
            $found = $this->stripe->customers->search(['query' => "metadata['ledgerly_tenant_id']:'{$key}'", 'limit' => 1]);
            if ($found->data !== []) {
                return (string) $found->data[0]->id;
            }

            // The idempotency key stops a retry racing the (eventually consistent) search from creating a second customer.
            $params = ['name' => $name, 'metadata' => ['ledgerly_tenant_id' => $tenantId]];
            if ($email !== '') {
                $params = ['name' => $name, 'email' => $email, 'metadata' => $params['metadata']];
            }

            return (string) $this->stripe->customers->create($params, ['idempotency_key' => 'ledgerly-customer-'.$tenantId])->id;
        });
    }

    public function createSubscription(string $customerId, string $priceId, ?DateTimeImmutable $trialEnd, ?string $couponId, int $quantity = 1): GatewaySubscription
    {
        return $this->call(function () use ($customerId, $priceId, $trialEnd, $couponId, $quantity): GatewaySubscription {
            $params = [
                'customer' => $customerId,
                'items' => [['price' => $priceId, 'quantity' => $quantity]],
                'payment_behavior' => 'allow_incomplete',
            ];
            if ($trialEnd !== null) {
                $params['trial_end'] = $trialEnd->getTimestamp();
            }
            if ($couponId !== null) {
                $params['discounts'] = [['coupon' => $couponId]];
            }

            return self::map($this->stripe->subscriptions->create($params));
        });
    }

    public function changePrice(string $subscriptionId, string $priceId, bool $prorate): GatewaySubscription
    {
        return $this->call(function () use ($subscriptionId, $priceId, $prorate): GatewaySubscription {
            $item = $this->itemId($subscriptionId);

            return self::map($this->stripe->subscriptions->update($subscriptionId, [
                'items' => [['id' => $item, 'price' => $priceId]],
                'proration_behavior' => $prorate ? 'create_prorations' : 'none',
            ]));
        });
    }

    public function previewChange(string $subscriptionId, string $priceId): GatewayInvoicePreview
    {
        return $this->call(function () use ($subscriptionId, $priceId): GatewayInvoicePreview {
            $item = $this->itemId($subscriptionId);
            $invoice = $this->stripe->invoices->createPreview([
                'subscription' => $subscriptionId,
                'subscription_details' => ['items' => [['id' => $item, 'price' => $priceId]], 'proration_behavior' => 'create_prorations'],
            ]);

            $lines = [];
            foreach ($invoice->lines->data as $line) {
                $lines[] = ['description' => (string) ($line->description ?? ''), 'amount' => (int) $line->amount];
            }

            return new GatewayInvoicePreview(strtoupper((string) $invoice->currency), (int) $invoice->amount_due, $lines);
        });
    }

    public function cancel(string $subscriptionId, bool $atPeriodEnd): GatewaySubscription
    {
        return $this->call(fn (): GatewaySubscription => self::map(
            $atPeriodEnd
                ? $this->stripe->subscriptions->update($subscriptionId, ['cancel_at_period_end' => true])
                : $this->stripe->subscriptions->cancel($subscriptionId),
        ));
    }

    public function resume(string $subscriptionId): GatewaySubscription
    {
        // An empty string unsets a nested Stripe parameter.
        return $this->call(fn (): GatewaySubscription => self::map(
            $this->update($subscriptionId, ['cancel_at_period_end' => false, 'pause_collection' => '']),
        ));
    }

    public function pause(string $subscriptionId, ?DateTimeImmutable $until): GatewaySubscription
    {
        $pause = ['behavior' => 'void'];
        if ($until !== null) {
            $pause['resumes_at'] = $until->getTimestamp();
        }

        return $this->call(fn (): GatewaySubscription => self::map($this->stripe->subscriptions->update($subscriptionId, ['pause_collection' => $pause])));
    }

    public function extendTrial(string $subscriptionId, DateTimeImmutable $trialEnd): GatewaySubscription
    {
        return $this->call(fn (): GatewaySubscription => self::map(
            $this->stripe->subscriptions->update($subscriptionId, ['trial_end' => $trialEnd->getTimestamp(), 'proration_behavior' => 'none']),
        ));
    }

    public function applyCoupon(string $subscriptionId, string $couponId): GatewaySubscription
    {
        return $this->call(fn (): GatewaySubscription => self::map($this->stripe->subscriptions->update($subscriptionId, ['discounts' => [['coupon' => $couponId]]])));
    }

    public function refund(string $invoiceId, int $amount, string $reason): string
    {
        return $this->call(function () use ($invoiceId, $amount, $reason): string {
            $paymentIntent = null;
            foreach ($this->stripe->invoicePayments->all(['invoice' => $invoiceId, 'status' => 'paid'])->data as $payment) {
                $inner = $payment->payment ?? null;
                if ($inner !== null && ($inner->type ?? null) === 'payment_intent' && ($inner->payment_intent ?? null) !== null) {
                    $paymentIntent = (string) $inner->payment_intent;

                    break;
                }
            }
            if ($paymentIntent === null) {
                throw DomainRuleViolation::single('billing_gateway_error', 'This invoice has no card payment to refund.');
            }

            return (string) $this->stripe->refunds->create([
                'payment_intent' => $paymentIntent, 'amount' => $amount, 'metadata' => ['reason' => mb_substr($reason, 0, 500)],
            ])->id;
        });
    }

    public function creditNote(string $invoiceId, int $amount, string $reason): string
    {
        return $this->call(fn (): string => (string) $this->stripe->creditNotes->create([
            'invoice' => $invoiceId,
            'memo' => mb_substr($reason, 0, 500),
            'credit_amount' => $amount,
            'lines' => [['type' => 'custom_line_item', 'description' => mb_substr($reason, 0, 250), 'quantity' => 1, 'unit_amount' => $amount]],
        ])->id);
    }

    public function upsertProduct(string $code, string $name): string
    {
        return $this->call(function () use ($code, $name): string {
            try {
                return (string) $this->stripe->products->retrieve($code)->id;
            } catch (InvalidRequestException $e) {
                if ($e->getStripeCode() !== 'resource_missing') {
                    throw $e;
                }
            }

            return (string) $this->stripe->products->create(['id' => $code, 'name' => $name])->id;
        });
    }

    public function upsertPrice(string $productId, string $currency, string $interval, int $amount): string
    {
        return $this->call(function () use ($productId, $currency, $interval, $amount): string {
            $lookup = "{$productId}:{$currency}:{$interval}:{$amount}";
            $found = $this->stripe->prices->all(['lookup_keys' => [$lookup], 'active' => true, 'limit' => 1]);
            if ($found->data !== []) {
                return (string) $found->data[0]->id;
            }

            return (string) $this->stripe->prices->create([
                'product' => $productId, 'currency' => strtolower($currency), 'unit_amount' => $amount,
                'recurring' => ['interval' => $interval], 'lookup_key' => $lookup,
            ])->id;
        });
    }

    public function upsertCoupon(array $spec): string
    {
        return $this->call(function () use ($spec): string {
            try {
                return (string) $this->stripe->coupons->retrieve($spec['code'])->id;
            } catch (InvalidRequestException $e) {
                if ($e->getStripeCode() !== 'resource_missing') {
                    throw $e;
                }
            }

            $params = ['id' => $spec['code']];
            if ($spec['percent'] !== null) {
                $params['percent_off'] = $spec['percent'];
            } else {
                $params['amount_off'] = (int) $spec['amount'];
                $params['currency'] = strtolower((string) $spec['currency']);
            }
            $params['duration'] = $spec['duration'];
            if ($spec['duration_months'] !== null) {
                $params['duration_in_months'] = $spec['duration_months'];
            }
            if ($spec['max_redemptions'] !== null) {
                $params['max_redemptions'] = $spec['max_redemptions'];
            }
            if ($spec['expires_at'] !== null) {
                $params['redeem_by'] = $spec['expires_at']->getTimestamp();
            }

            return (string) $this->stripe->coupons->create($params)->id;
        });
    }

    /**
     * Subscription update with loosely typed parameters: Stripe unsets a nested parameter with an empty string, which
     * stripe-php's generated parameter shapes do not allow.
     *
     * @param  array<string, mixed>  $params
     */
    private function update(string $subscriptionId, array $params): Subscription
    {
        return $this->stripe->subscriptions->update($subscriptionId, $params);
    }

    private function itemId(string $subscriptionId): string
    {
        $items = $this->stripe->subscriptions->retrieve($subscriptionId)->items->data;
        if ($items === []) {
            throw DomainRuleViolation::single('billing_gateway_error', 'The Stripe subscription has no items.');
        }

        return (string) $items[0]->id;
    }

    private static function map(Subscription $s): GatewaySubscription
    {
        $paused = $s->pause_collection !== null;
        $status = $paused ? 'paused' : (self::STATUS[(string) $s->status] ?? 'past_due');

        $item = $s->items->data[0] ?? null;
        $end = $item?->current_period_end;
        $cancelAt = $s->cancel_at ?? (($s->cancel_at_period_end ?? false) ? $end : null);

        return new GatewaySubscription(
            (string) $s->id, $status, self::time($end), self::time($s->trial_end ?? null), self::time($cancelAt),
        );
    }

    private static function time(mixed $timestamp): ?DateTimeImmutable
    {
        return is_int($timestamp) ? new DateTimeImmutable('@'.$timestamp) : null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function call(Closure $work): mixed
    {
        try {
            return $work();
        } catch (ApiErrorException $e) {
            throw DomainRuleViolation::single('billing_gateway_error', 'Stripe refused the request: '.$e->getMessage());
        }
    }
}
