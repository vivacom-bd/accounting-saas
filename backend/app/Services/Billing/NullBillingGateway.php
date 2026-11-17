<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\BillingGateway;
use App\Domain\Shared\DomainRuleViolation;
use App\Services\Billing\Dto\GatewayInvoicePreview;
use App\Services\Billing\Dto\GatewaySubscription;
use DateTimeImmutable;

/** Bound when no Stripe secret is configured (G2): every call refuses with `billing_not_configured` (503). */
final class NullBillingGateway implements BillingGateway
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function ensureCustomer(string $tenantId, string $name, string $email): string
    {
        throw self::notConfigured();
    }

    public function createSubscription(string $customerId, string $priceId, ?DateTimeImmutable $trialEnd, ?string $couponId, int $quantity = 1): GatewaySubscription
    {
        throw self::notConfigured();
    }

    public function changePrice(string $subscriptionId, string $priceId, bool $prorate): GatewaySubscription
    {
        throw self::notConfigured();
    }

    public function previewChange(string $subscriptionId, string $priceId): GatewayInvoicePreview
    {
        throw self::notConfigured();
    }

    public function cancel(string $subscriptionId, bool $atPeriodEnd): GatewaySubscription
    {
        throw self::notConfigured();
    }

    public function resume(string $subscriptionId): GatewaySubscription
    {
        throw self::notConfigured();
    }

    public function pause(string $subscriptionId, ?DateTimeImmutable $until): GatewaySubscription
    {
        throw self::notConfigured();
    }

    public function extendTrial(string $subscriptionId, DateTimeImmutable $trialEnd): GatewaySubscription
    {
        throw self::notConfigured();
    }

    public function applyCoupon(string $subscriptionId, string $couponId): GatewaySubscription
    {
        throw self::notConfigured();
    }

    public function refund(string $invoiceId, int $amount, string $reason): string
    {
        throw self::notConfigured();
    }

    public function creditNote(string $invoiceId, int $amount, string $reason): string
    {
        throw self::notConfigured();
    }

    public function upsertProduct(string $code, string $name): string
    {
        throw self::notConfigured();
    }

    public function upsertPrice(string $productId, string $currency, string $interval, int $amount): string
    {
        throw self::notConfigured();
    }

    public function upsertCoupon(array $spec): string
    {
        throw self::notConfigured();
    }

    public static function notConfigured(): DomainRuleViolation
    {
        return DomainRuleViolation::single('billing_not_configured', 'Stripe is not configured on this installation. Offline (invoiced) subscriptions still work.');
    }
}
