<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\BillingGateway;
use App\Services\Billing\Dto\GatewayInvoicePreview;
use App\Services\Billing\Dto\GatewaySubscription;
use DateTimeImmutable;

/**
 * In-memory BillingGateway for tests (plan G2): records every call, answers with deterministic ids and a state the test
 * can steer through `$status` and `$periodEnd`. Never talks to Stripe.
 */
final class FakeBillingGateway implements BillingGateway
{
    /** @var list<array{method: string, args: array<array-key, mixed>}> */
    public array $calls = [];

    /** The status the next subscription-returning call answers with. */
    public string $status = 'active';

    public DateTimeImmutable $periodEnd;

    /** @var array<string, string> subscription id => status, so a later call keeps what an earlier one set */
    private array $statuses = [];

    private int $seq = 0;

    public function __construct()
    {
        $this->periodEnd = new DateTimeImmutable('2026-04-15 09:30:00+00');
    }

    /** @return list<array<array-key, mixed>> */
    public function callsTo(string $method): array
    {
        return array_values(array_map(fn (array $c): array => $c['args'], array_filter($this->calls, fn (array $c): bool => $c['method'] === $method)));
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function ensureCustomer(string $tenantId, string $name, string $email): string
    {
        $this->record(__FUNCTION__, get_defined_vars());

        return 'cus_'.substr($tenantId, -8);
    }

    public function createSubscription(string $customerId, string $priceId, ?DateTimeImmutable $trialEnd, ?string $couponId, int $quantity = 1): GatewaySubscription
    {
        $this->record(__FUNCTION__, get_defined_vars());

        $id = 'sub_fake_'.(++$this->seq);
        $this->statuses[$id] = $trialEnd !== null ? 'trialing' : $this->status;

        return new GatewaySubscription($id, $this->statuses[$id], $this->periodEnd, $trialEnd, null);
    }

    public function changePrice(string $subscriptionId, string $priceId, bool $prorate): GatewaySubscription
    {
        $this->record(__FUNCTION__, get_defined_vars());

        return $this->sub($subscriptionId);
    }

    public function previewChange(string $subscriptionId, string $priceId): GatewayInvoicePreview
    {
        $this->record(__FUNCTION__, get_defined_vars());

        return new GatewayInvoicePreview('USD', 1234, [['description' => 'Proration', 'amount' => 1234]]);
    }

    public function cancel(string $subscriptionId, bool $atPeriodEnd): GatewaySubscription
    {
        $this->record(__FUNCTION__, get_defined_vars());

        if (! $atPeriodEnd) {
            $this->statuses[$subscriptionId] = 'cancelled';

            return new GatewaySubscription($subscriptionId, 'cancelled', $this->periodEnd, null, null);
        }

        return new GatewaySubscription($subscriptionId, $this->statuses[$subscriptionId] ?? $this->status, $this->periodEnd, null, $this->periodEnd);
    }

    public function resume(string $subscriptionId): GatewaySubscription
    {
        $this->record(__FUNCTION__, get_defined_vars());

        return $this->sub($subscriptionId);
    }

    public function pause(string $subscriptionId, ?DateTimeImmutable $until): GatewaySubscription
    {
        $this->record(__FUNCTION__, get_defined_vars());

        $this->statuses[$subscriptionId] = 'paused';

        return new GatewaySubscription($subscriptionId, 'paused', $this->periodEnd, null, null);
    }

    public function extendTrial(string $subscriptionId, DateTimeImmutable $trialEnd): GatewaySubscription
    {
        $this->record(__FUNCTION__, get_defined_vars());

        $this->statuses[$subscriptionId] = $trialEnd > new DateTimeImmutable('2026-03-15 09:30:00+00') ? 'trialing' : $this->status;

        return new GatewaySubscription($subscriptionId, $this->statuses[$subscriptionId], $trialEnd, $trialEnd, null);
    }

    public function applyCoupon(string $subscriptionId, string $couponId): GatewaySubscription
    {
        $this->record(__FUNCTION__, get_defined_vars());

        return $this->sub($subscriptionId);
    }

    public function refund(string $invoiceId, int $amount, string $reason): string
    {
        $this->record(__FUNCTION__, get_defined_vars());

        return 're_fake_'.(++$this->seq);
    }

    public function creditNote(string $invoiceId, int $amount, string $reason): string
    {
        $this->record(__FUNCTION__, get_defined_vars());

        return 'cn_fake_'.(++$this->seq);
    }

    public function upsertProduct(string $code, string $name): string
    {
        $this->record(__FUNCTION__, get_defined_vars());

        return 'prod_'.$code;
    }

    public function upsertPrice(string $productId, string $currency, string $interval, int $amount): string
    {
        $this->record(__FUNCTION__, get_defined_vars());

        return "price_{$productId}_{$currency}_{$interval}";
    }

    public function upsertCoupon(array $spec): string
    {
        $this->record(__FUNCTION__, get_defined_vars());

        return 'coupon_'.$spec['code'];
    }

    private function sub(string $id): GatewaySubscription
    {
        // A pause ends on resume; anything else keeps its status.
        $status = $this->statuses[$id] ?? $this->status;
        $this->statuses[$id] = $status === 'paused' ? 'active' : $status;

        return new GatewaySubscription($id, $this->statuses[$id], $this->periodEnd, null, null);
    }

    /** @param  array<array-key, mixed>  $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}
