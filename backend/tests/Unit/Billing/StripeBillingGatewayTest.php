<?php

declare(strict_types=1);

use App\Domain\Shared\DomainRuleViolation;
use App\Services\Billing\StripeBillingGateway;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

/**
 * Request mapping of the Stripe gateway, checked against a scripted HTTP client: nothing here reaches Stripe and the
 * answers are hand-written, so this proves what we send and how we read the reply, not that Stripe accepts it (G2).
 */
final class ScriptedStripeHttp implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<array-key, mixed>}> */
    public array $requests = [];

    /** @var array<string, array{int, array<array-key, mixed>}> "METHOD /v1/path" => [status, body] */
    public array $routes = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => strtoupper($method), 'path' => $path, 'params' => (array) $params];
        [$status, $body] = $this->routes[strtoupper($method).' '.$path] ?? [404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such thing']]];

        return [json_encode($body, JSON_THROW_ON_ERROR), $status, []];
    }

    /** @return array{method: string, path: string, params: array<array-key, mixed>} */
    public function last(): array
    {
        return $this->requests[array_key_last($this->requests)];
    }
}

function scriptedStripeSub(string $status = 'active', array $over = []): array
{
    return $over + [
        'id' => 'sub_1', 'object' => 'subscription', 'status' => $status, 'customer' => 'cus_1', 'trial_end' => null, 'cancel_at' => null, 'pause_collection' => null,
        'items' => ['object' => 'list', 'has_more' => false, 'url' => '/v1/subscription_items', 'data' => [
            ['id' => 'si_1', 'object' => 'subscription_item', 'current_period_end' => 1776245400, 'price' => ['id' => 'price_old']],
        ]],
    ];
}

beforeEach(function (): void {
    $this->http = new ScriptedStripeHttp;
    ApiRequestor::setHttpClient($this->http);
    $this->gateway = new StripeBillingGateway(new StripeClient(['api_key' => 'sk_test_unit', 'max_network_retries' => 0]));
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

it('is configured', function (): void {
    expect($this->gateway->isConfigured())->toBeTrue();
});

it('finds the customer by tenant metadata before creating one', function (): void {
    $this->http->routes['GET /v1/customers/search'] = [200, ['object' => 'search_result', 'url' => '/v1/customers/search', 'has_more' => false, 'data' => [['id' => 'cus_existing', 'object' => 'customer']]]];

    expect($this->gateway->ensureCustomer('t-1', 'Acme', 'owner@acme.test'))->toBe('cus_existing')
        ->and($this->http->requests)->toHaveCount(1)
        ->and($this->http->last()['params']['query'])->toContain("metadata['ledgerly_tenant_id']:'t-1'");
});

it('creates the customer with the tenant id in metadata when none exists', function (): void {
    $this->http->routes['GET /v1/customers/search'] = [200, ['object' => 'search_result', 'url' => '/v1/customers/search', 'has_more' => false, 'data' => []]];
    $this->http->routes['POST /v1/customers'] = [200, ['id' => 'cus_new', 'object' => 'customer']];

    expect($this->gateway->ensureCustomer('t-1', 'Acme', 'owner@acme.test'))->toBe('cus_new')
        ->and($this->http->last()['params'])->toMatchArray(['name' => 'Acme', 'email' => 'owner@acme.test', 'metadata' => ['ledgerly_tenant_id' => 't-1']]);
});

it('creates a trialing subscription with the price, quantity, trial end and coupon', function (): void {
    $this->http->routes['POST /v1/subscriptions'] = [200, scriptedStripeSub('trialing', ['trial_end' => 1775640600])];

    $sub = $this->gateway->createSubscription('cus_1', 'price_pro', new DateTimeImmutable('2026-04-08 09:30:00+00'), 'WELCOME10', 2);

    $params = $this->http->last()['params'];
    expect($params['customer'])->toBe('cus_1')
        ->and($params['items'])->toBe([['price' => 'price_pro', 'quantity' => 2]])
        ->and($params['trial_end'])->toBe((new DateTimeImmutable('2026-04-08 09:30:00+00'))->getTimestamp())
        ->and($params['discounts'])->toBe([['coupon' => 'WELCOME10']])
        ->and($params['payment_behavior'])->toBe('allow_incomplete')
        ->and($sub->id)->toBe('sub_1')
        ->and($sub->status)->toBe('trialing')
        ->and($sub->currentPeriodEnd->getTimestamp())->toBe(1776245400)   // read from the item: the period moved off the subscription
        ->and($sub->trialEnd?->getTimestamp())->toBe(1775640600)
        ->and($sub->cancelAt)->toBeNull();
});

it('omits trial and coupon when there are none', function (): void {
    $this->http->routes['POST /v1/subscriptions'] = [200, scriptedStripeSub()];

    $this->gateway->createSubscription('cus_1', 'price_pro', null, null);

    expect($this->http->last()['params'])->not->toHaveKeys(['trial_end', 'discounts']);
});

it('swaps the price on the existing item with or without proration', function (bool $prorate, string $behaviour): void {
    $this->http->routes['GET /v1/subscriptions/sub_1'] = [200, scriptedStripeSub()];
    $this->http->routes['POST /v1/subscriptions/sub_1'] = [200, scriptedStripeSub()];

    $this->gateway->changePrice('sub_1', 'price_biz', $prorate);

    expect($this->http->last()['params'])->toMatchArray(['items' => [['id' => 'si_1', 'price' => 'price_biz']], 'proration_behavior' => $behaviour]);
})->with([[true, 'create_prorations'], [false, 'none']]);

it('previews a plan change as an upcoming-invoice preview', function (): void {
    $this->http->routes['GET /v1/subscriptions/sub_1'] = [200, scriptedStripeSub()];
    $this->http->routes['POST /v1/invoices/create_preview'] = [200, [
        'object' => 'invoice', 'currency' => 'usd', 'amount_due' => 1234,
        'lines' => ['object' => 'list', 'has_more' => false, 'url' => '/x', 'data' => [['id' => 'il_1', 'object' => 'line_item', 'description' => 'Unused time on Pro', 'amount' => -400], ['id' => 'il_2', 'object' => 'line_item', 'description' => 'Remaining time on Business', 'amount' => 1634]]],
    ]];

    $preview = $this->gateway->previewChange('sub_1', 'price_biz');

    expect($this->http->last()['params'])->toMatchArray(['subscription' => 'sub_1', 'subscription_details' => ['items' => [['id' => 'si_1', 'price' => 'price_biz']], 'proration_behavior' => 'create_prorations']])
        ->and($preview->currency)->toBe('USD')
        ->and($preview->amountDue)->toBe(1234)
        ->and($preview->lines)->toBe([['description' => 'Unused time on Pro', 'amount' => -400], ['description' => 'Remaining time on Business', 'amount' => 1634]]);
});

it('cancels at period end by updating, and immediately by deleting', function (): void {
    $this->http->routes['POST /v1/subscriptions/sub_1'] = [200, scriptedStripeSub('active', ['cancel_at' => 1776245400])];
    $this->http->routes['DELETE /v1/subscriptions/sub_1'] = [200, scriptedStripeSub('canceled')];

    $later = $this->gateway->cancel('sub_1', true);
    expect($this->http->last())->toMatchArray(['method' => 'POST'])
        ->and($this->http->last()['params'])->toBe(['cancel_at_period_end' => 'true'])   // stripe-php sends booleans as 'true'/'false'
        ->and($later->status)->toBe('active')
        ->and($later->cancelAt?->getTimestamp())->toBe(1776245400);

    $now = $this->gateway->cancel('sub_1', false);
    expect($this->http->last()['method'])->toBe('DELETE')->and($now->status)->toBe('cancelled');
});

it('resumes by clearing the pending cancellation and any pause', function (): void {
    $this->http->routes['POST /v1/subscriptions/sub_1'] = [200, scriptedStripeSub()];

    $this->gateway->resume('sub_1');

    expect($this->http->last()['params'])->toBe(['cancel_at_period_end' => 'false', 'pause_collection' => '']);
});

it('pauses collection and reads it back as paused', function (): void {
    $this->http->routes['POST /v1/subscriptions/sub_1'] = [200, scriptedStripeSub('active', ['pause_collection' => ['behavior' => 'void', 'resumes_at' => 1777000000]])];

    $sub = $this->gateway->pause('sub_1', new DateTimeImmutable('@1777000000'));

    expect($this->http->last()['params'])->toBe(['pause_collection' => ['behavior' => 'void', 'resumes_at' => 1777000000]])
        ->and($sub->status)->toBe('paused');

    $this->gateway->pause('sub_1', null);
    expect($this->http->last()['params'])->toBe(['pause_collection' => ['behavior' => 'void']]);
});

it('moves the trial end without prorating', function (): void {
    $this->http->routes['POST /v1/subscriptions/sub_1'] = [200, scriptedStripeSub('trialing', ['trial_end' => 1776000000])];

    $sub = $this->gateway->extendTrial('sub_1', new DateTimeImmutable('@1776000000'));

    expect($this->http->last()['params'])->toBe(['trial_end' => 1776000000, 'proration_behavior' => 'none'])
        ->and($sub->trialEnd?->getTimestamp())->toBe(1776000000);
});

it('applies a coupon as a subscription discount', function (): void {
    $this->http->routes['POST /v1/subscriptions/sub_1'] = [200, scriptedStripeSub()];

    $this->gateway->applyCoupon('sub_1', 'WELCOME10');

    expect($this->http->last()['params'])->toBe(['discounts' => [['coupon' => 'WELCOME10']]]);
});

it('maps Stripe statuses to ours', function (string $stripe, string $ours): void {
    $this->http->routes['POST /v1/subscriptions/sub_1'] = [200, scriptedStripeSub($stripe)];

    expect($this->gateway->resume('sub_1')->status)->toBe($ours);
})->with([
    ['trialing', 'trialing'], ['active', 'active'], ['past_due', 'past_due'], ['unpaid', 'past_due'], ['incomplete', 'past_due'],
    ['paused', 'paused'], ['canceled', 'cancelled'], ['incomplete_expired', 'cancelled'],
]);

it('refunds the payment intent that paid the invoice', function (): void {
    $this->http->routes['GET /v1/invoice_payments'] = [200, ['object' => 'list', 'url' => '/v1/invoice_payments', 'has_more' => false, 'data' => [
        ['id' => 'inpay_1', 'object' => 'invoice_payment', 'status' => 'paid', 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_1']],
    ]]];
    $this->http->routes['POST /v1/refunds'] = [200, ['id' => 're_1', 'object' => 'refund']];

    expect($this->gateway->refund('in_1', 2500, 'Duplicate charge'))->toBe('re_1')
        ->and($this->http->requests[0]['params'])->toMatchArray(['invoice' => 'in_1'])
        ->and($this->http->last()['params'])->toMatchArray(['payment_intent' => 'pi_1', 'amount' => 2500, 'metadata' => ['reason' => 'Duplicate charge']]);
});

it('refuses to refund an invoice that has no payment', function (): void {
    $this->http->routes['GET /v1/invoice_payments'] = [200, ['object' => 'list', 'url' => '/v1/invoice_payments', 'has_more' => false, 'data' => []]];

    $this->gateway->refund('in_1', 2500, 'x');
})->throws(DomainRuleViolation::class);

it('issues a credit note for an amount', function (): void {
    $this->http->routes['POST /v1/credit_notes'] = [200, ['id' => 'cn_1', 'object' => 'credit_note']];

    expect($this->gateway->creditNote('in_1', 900, 'Goodwill'))->toBe('cn_1')
        ->and($this->http->last()['params'])->toMatchArray(['invoice' => 'in_1', 'memo' => 'Goodwill', 'credit_amount' => 900, 'lines' => [['type' => 'custom_line_item', 'description' => 'Goodwill', 'quantity' => 1, 'unit_amount' => 900]]]);
});

it('reuses a product and creates it under the code when missing', function (): void {
    $this->http->routes['GET /v1/products/pro-v1'] = [200, ['id' => 'pro-v1', 'object' => 'product']];
    expect($this->gateway->upsertProduct('pro-v1', 'Pro v1'))->toBe('pro-v1')->and($this->http->requests)->toHaveCount(1);

    unset($this->http->routes['GET /v1/products/pro-v1']);
    $this->http->routes['POST /v1/products'] = [200, ['id' => 'pro-v1', 'object' => 'product']];
    expect($this->gateway->upsertProduct('pro-v1', 'Pro v1'))->toBe('pro-v1')
        ->and($this->http->last()['params'])->toBe(['id' => 'pro-v1', 'name' => 'Pro v1']);
});

it('finds a price by lookup key and creates it when missing', function (): void {
    $this->http->routes['GET /v1/prices'] = [200, ['object' => 'list', 'url' => '/v1/prices', 'has_more' => false, 'data' => [['id' => 'price_found', 'object' => 'price']]]];
    expect($this->gateway->upsertPrice('pro-v1', 'USD', 'month', 4900))->toBe('price_found')
        ->and($this->http->last()['params']['lookup_keys'])->toBe(['pro-v1:USD:month:4900']);

    $this->http->routes['GET /v1/prices'] = [200, ['object' => 'list', 'url' => '/v1/prices', 'has_more' => false, 'data' => []]];
    $this->http->routes['POST /v1/prices'] = [200, ['id' => 'price_new', 'object' => 'price']];
    expect($this->gateway->upsertPrice('pro-v1', 'USD', 'year', 47000))->toBe('price_new')
        ->and($this->http->last()['params'])->toBe([
            'product' => 'pro-v1', 'currency' => 'usd', 'unit_amount' => 47000, 'recurring' => ['interval' => 'year'], 'lookup_key' => 'pro-v1:USD:year:47000',
        ]);
});

it('creates percent and amount coupons under their code', function (): void {
    $this->http->routes['POST /v1/coupons'] = [200, ['id' => 'WELCOME10', 'object' => 'coupon']];

    $this->gateway->upsertCoupon(['code' => 'WELCOME10', 'percent' => 10, 'amount' => null, 'currency' => null, 'duration' => 'repeating', 'duration_months' => 3, 'max_redemptions' => 50, 'expires_at' => new DateTimeImmutable('@1790000000')]);
    expect($this->http->last()['params'])->toBe(['id' => 'WELCOME10', 'percent_off' => 10, 'duration' => 'repeating', 'duration_in_months' => 3, 'max_redemptions' => 50, 'redeem_by' => 1790000000]);

    $this->gateway->upsertCoupon(['code' => 'EUR5', 'percent' => null, 'amount' => 500, 'currency' => 'EUR', 'duration' => 'once', 'duration_months' => null, 'max_redemptions' => null, 'expires_at' => null]);
    expect($this->http->last()['params'])->toBe(['id' => 'EUR5', 'amount_off' => 500, 'currency' => 'eur', 'duration' => 'once']);
});

it('turns a Stripe error into a gateway problem without leaking anything but its message', function (): void {
    $this->http->routes['POST /v1/subscriptions/sub_1'] = [402, ['error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.']]];

    try {
        $this->gateway->resume('sub_1');
        $this->fail('expected a gateway problem');
    } catch (DomainRuleViolation $e) {
        expect($e->errorCode)->toBe('billing_gateway_error')->and($e->getMessage())->toContain('Your card was declined.')->not->toContain('sk_test');
    }
});
