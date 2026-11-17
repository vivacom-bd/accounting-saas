<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Billing\Dto\GatewayInvoicePreview;
use App\Services\Billing\Dto\GatewaySubscription;
use DateTimeImmutable;

/**
 * The payment processor behind subscriptions (plan G2, T5). Stripe in production; `NullBillingGateway` when no keys are
 * set (every call refuses with `billing_not_configured`); `FakeBillingGateway` in tests. Money is integer minor units.
 * Every method that returns a subscription answers with the processor's state in OUR vocabulary
 * (trialing, active, past_due, paused, cancelled), which Actions mirror into the local row.
 */
interface BillingGateway
{
    /** True when real calls can be made. The only method that never throws. */
    public function isConfigured(): bool;

    /** The processor's customer id for the tenant, created once. */
    public function ensureCustomer(string $tenantId, string $name, string $email): string;

    public function createSubscription(string $customerId, string $priceId, ?DateTimeImmutable $trialEnd, ?string $couponId, int $quantity = 1): GatewaySubscription;

    public function changePrice(string $subscriptionId, string $priceId, bool $prorate): GatewaySubscription;

    /** What changing to the price would invoice now (proration included). */
    public function previewChange(string $subscriptionId, string $priceId): GatewayInvoicePreview;

    public function cancel(string $subscriptionId, bool $atPeriodEnd): GatewaySubscription;

    /** Withdraws a pending cancellation and ends a pause. */
    public function resume(string $subscriptionId): GatewaySubscription;

    public function pause(string $subscriptionId, ?DateTimeImmutable $until): GatewaySubscription;

    public function extendTrial(string $subscriptionId, DateTimeImmutable $trialEnd): GatewaySubscription;

    public function applyCoupon(string $subscriptionId, string $couponId): GatewaySubscription;

    /** Returns the refund id. */
    public function refund(string $invoiceId, int $amount, string $reason): string;

    /** Returns the credit note id. */
    public function creditNote(string $invoiceId, int $amount, string $reason): string;

    /** Idempotent: the same code always answers the same product id. */
    public function upsertProduct(string $code, string $name): string;

    /**
     * Idempotent per (product, currency, interval, amount).
     *
     * @param  'month'|'year'  $interval
     */
    public function upsertPrice(string $productId, string $currency, string $interval, int $amount): string;

    /**
     * Idempotent per coupon code.
     *
     * @param  array{code: string, percent: int|null, amount: int|null, currency: string|null, duration: string, duration_months: int|null, max_redemptions: int|null, expires_at: DateTimeImmutable|null}  $spec
     */
    public function upsertCoupon(array $spec): string;
}
