<?php

declare(strict_types=1);

use App\Notifications\Billing\PaymentFailed;
use App\Notifications\Billing\PaymentReminder;
use App\Notifications\Billing\TrialEnding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BillingScenario;

const STRIPE_TEST_SECRET = 'whsec_test_secret';
const STRIPE_NOW = 1773567000; // 2026-03-15 09:30:00 UTC, the frozen test clock

beforeEach(function (): void {
    Notification::fake();
    config(['cashier.webhook.secret' => STRIPE_TEST_SECRET]);
});

/**
 * A fixture event with overrides merged in.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function stripeEvent(string $fixture, array $over = []): array
{
    $json = file_get_contents(__DIR__.'/../../fixtures/stripe/'.$fixture.'.json');
    /** @var array<string, mixed> $event */
    $event = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

    return array_replace_recursive($event, $over);
}

/** @param  array<string, mixed>  $event */
function stripeSend(array $event, ?string $secret = STRIPE_TEST_SECRET): TestResponse
{
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($secret !== null) {
        $t = time();
        $headers['HTTP_STRIPE_SIGNATURE'] = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", $secret);
    }

    return test()->call('POST', '/api/stripe/webhook', [], [], [], $headers, $payload);
}

/** A tenant with a Stripe subscription row (customer cus_test_1, subscription sub_test_1). */
function stripeTenant(string $lifecycle = 'trial', string $subStatus = 'trialing', array $tenant = []): string
{
    $version = BillingScenario::plan('pro');
    $id = BillingScenario::tenant($tenant + ['lifecycle' => $lifecycle]);
    DB::connection('pgsql_platform')->table('subscriptions')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $id, 'plan_version_id' => $version, 'collection' => 'stripe',
        'interval' => 'month', 'currency' => 'USD', 'quantity' => 1, 'status' => $subStatus,
        'stripe_subscription_id' => 'sub_test_1', 'stripe_customer_id' => 'cus_test_1', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function stripeTenantRow(string $id): object
{
    /** @var object */
    return DB::connection('pgsql_platform')->table('tenants')->where('id', $id)->first();
}

function stripeSubRow(string $tenantId): object
{
    /** @var object */
    return DB::connection('pgsql_platform')->table('subscriptions')->where('tenant_id', $tenantId)->first();
}

it('bad_signature_is_400', function (): void {
    stripeSend(stripeEvent('invoice_paid'), 'whsec_someone_else')->assertStatus(400);
    stripeSend(stripeEvent('invoice_paid'), null)->assertStatus(400);

    expect(DB::connection('pgsql_platform')->table('stripe_events')->count())->toBe(0);
});

it('without_a_webhook_secret_nothing_is_accepted_503', function (): void {
    config(['cashier.webhook.secret' => '']);

    stripeSend(stripeEvent('invoice_paid'), '')->assertStatus(503)->assertJsonPath('code', 'billing_not_configured');

    expect(DB::connection('pgsql_platform')->table('stripe_events')->count())->toBe(0);
});

it('same_event_twice_processed_once', function (): void {
    $tenant = stripeTenant('past_due', 'past_due');

    stripeSend(stripeEvent('invoice_paid'))->assertOk();
    stripeSend(stripeEvent('invoice_paid'))->assertOk();

    $events = DB::connection('pgsql_platform')->table('stripe_events')->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->processed_at)->not->toBeNull()
        ->and($events[0]->error)->toBeNull()
        ->and(DB::connection('pgsql_platform')->table('tenant_lifecycle_events')->where('tenant_id', $tenant)->where('to_status', 'active')->count())->toBe(1)
        ->and(DB::connection('pgsql_platform')->table('stripe_invoices')->count())->toBe(1);
});

it('invoice_paid_before_subscription_created_ends_active', function (): void {
    $tenant = stripeTenant('trial', 'trialing');

    // Stripe delivers the newer invoice.paid first, then the older subscription.created (status still "incomplete").
    stripeSend(stripeEvent('invoice_paid'))->assertOk();
    stripeSend(stripeEvent('subscription_created'))->assertOk();

    $sub = stripeSubRow($tenant);
    expect($sub->status)->toBe('active')
        ->and(strtotime($sub->updated_from_event_at))->toBe(STRIPE_NOW)
        ->and(stripeTenantRow($tenant)->lifecycle)->toBe('active');
    // The ignored event is still recorded as processed.
    expect(DB::connection('pgsql_platform')->table('stripe_events')->whereNull('processed_at')->count())->toBe(0);
});

it('older_event_does_not_overwrite_newer_state', function (): void {
    $tenant = stripeTenant('active', 'active');
    $newer = stripeEvent('subscription_created', ['id' => 'evt_new', 'type' => 'customer.subscription.updated', 'created' => STRIPE_NOW + 100, 'data' => ['object' => ['status' => 'past_due']]]);
    $older = stripeEvent('subscription_created', ['id' => 'evt_old', 'type' => 'customer.subscription.updated', 'created' => STRIPE_NOW - 100, 'data' => ['object' => ['status' => 'active']]]);

    stripeSend($newer)->assertOk();
    stripeSend($older)->assertOk();

    $sub = stripeSubRow($tenant);
    expect($sub->status)->toBe('past_due')
        ->and(strtotime($sub->updated_from_event_at))->toBe(STRIPE_NOW + 100);
});

it('payment_failed_sets_past_due_and_delinquent_since_once', function (): void {
    $tenant = stripeTenant('active', 'active');

    stripeSend(stripeEvent('invoice_payment_failed'))->assertOk();
    $first = stripeTenantRow($tenant);
    expect($first->lifecycle)->toBe('past_due')
        ->and(strtotime($first->delinquent_since))->toBe(STRIPE_NOW)
        ->and(stripeSubRow($tenant)->status)->toBe('past_due');

    // A later retry failure must not restart the grace clock.
    stripeSend(stripeEvent('invoice_payment_failed', ['id' => 'evt_failed_2', 'created' => STRIPE_NOW + 86400 * 3]))->assertOk();

    $second = stripeTenantRow($tenant);
    expect($second->lifecycle)->toBe('past_due')
        ->and(strtotime($second->delinquent_since))->toBe(STRIPE_NOW)
        ->and(DB::connection('pgsql_platform')->table('tenant_lifecycle_events')->where('tenant_id', $tenant)->where('to_status', 'past_due')->count())->toBe(1);
    Notification::assertSentOnDemandTimes(PaymentFailed::class, 2);
});

it('invoice_paid_brings_a_delinquent_tenant_back_to_active', function (): void {
    $tenant = stripeTenant('suspended', 'past_due', ['delinquent_since' => '2026-02-20 00:00:00+00']);

    stripeSend(stripeEvent('invoice_paid'))->assertOk();

    $row = stripeTenantRow($tenant);
    expect($row->lifecycle)->toBe('active')->and($row->delinquent_since)->toBeNull()
        ->and(stripeSubRow($tenant)->status)->toBe('active');
});

it('a_late_payment_does_not_reactivate_a_cancelled_tenant_but_flags_it', function (): void {
    $tenant = stripeTenant('cancelled', 'cancelled');

    stripeSend(stripeEvent('invoice_paid'))->assertOk();

    expect(stripeTenantRow($tenant)->lifecycle)->toBe('cancelled');
    $event = DB::connection('pgsql_platform')->table('stripe_events')->first();
    expect($event->processed_at)->not->toBeNull()
        ->and($event->attention)->toContain('cancelled');
    expect(DB::connection('pgsql_platform')->table('stripe_invoices')->where('stripe_id', 'in_test_1')->value('status'))->toBe('paid');
});

it('unknown_customer_is_recorded_and_ignored', function (): void {
    stripeSend(stripeEvent('invoice_paid', ['data' => ['object' => ['customer' => 'cus_nobody']]]))->assertOk();

    $event = DB::connection('pgsql_platform')->table('stripe_events')->first();
    expect($event->processed_at)->not->toBeNull()->and($event->error)->toBeNull()
        ->and(DB::connection('pgsql_platform')->table('stripe_invoices')->count())->toBe(0);
});

it('invoice_is_mirrored_with_lines_and_tax', function (): void {
    $tenant = stripeTenant('active', 'active');

    stripeSend(stripeEvent('invoice_paid'))->assertOk();

    $inv = DB::connection('pgsql_platform')->table('stripe_invoices')->first();
    expect($inv->tenant_id)->toBe($tenant)
        ->and($inv->stripe_id)->toBe('in_test_1')
        ->and($inv->source)->toBe('stripe')
        ->and($inv->number)->toBe('LDG-0001')
        ->and($inv->status)->toBe('paid')
        ->and($inv->currency)->toBe('USD')
        ->and([$inv->subtotal, $inv->tax, $inv->total, $inv->amount_paid, $inv->amount_refunded])->toBe([4900, 784, 5684, 5684, 0])
        ->and(strtotime($inv->paid_at))->toBe(STRIPE_NOW)
        ->and(strtotime($inv->period_end))->toBe(1776245400)
        ->and($inv->pdf_url)->toBe('https://pay.example.test/in_test_1.pdf');
    $lines = json_decode($inv->lines, true);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['description'])->toBe('Pro plan (monthly)')
        ->and($lines[0]['amount'])->toBe(4900)
        ->and($lines[0]['plan_code'])->toBe('pro')
        ->and(strtotime($lines[0]['period_start']))->toBe(STRIPE_NOW);
});

it('refund_event_updates_amount_refunded', function (): void {
    stripeTenant('active', 'active');
    stripeSend(stripeEvent('invoice_paid'))->assertOk();

    stripeSend(stripeEvent('charge_refunded'))->assertOk();
    expect((int) DB::connection('pgsql_platform')->table('stripe_invoices')->value('amount_refunded'))->toBe(1000);

    // A replayed smaller total (older delivery) never lowers it.
    stripeSend(stripeEvent('charge_refunded', ['id' => 'evt_refund_old', 'data' => ['object' => ['amount_refunded' => 400]]]))->assertOk();
    expect((int) DB::connection('pgsql_platform')->table('stripe_invoices')->value('amount_refunded'))->toBe(1000);
});

it('payout_is_mirrored', function (): void {
    stripeSend(stripeEvent('payout_paid'))->assertOk();

    $p = DB::connection('pgsql_platform')->table('stripe_payouts')->first();
    expect($p->stripe_id)->toBe('po_test_1')->and($p->amount)->toBe(120000)->and($p->currency)->toBe('USD')
        ->and($p->status)->toBe('paid')->and(substr((string) $p->arrival_date, 0, 10))->toBe('2026-03-17');
});

it('a_failing_event_is_recorded_and_the_webhook_still_acknowledges', function (): void {
    stripeTenant('active', 'active');
    // An invoice without an id cannot be mirrored.
    $event = stripeEvent('invoice_paid');
    unset($event['data']['object']['id']);

    stripeSend($event)->assertOk();

    $row = DB::connection('pgsql_platform')->table('stripe_events')->first();
    expect($row->error)->not->toBeNull()->and($row->processed_at)->toBeNull();
});

it('dunning_sends_day_1_4_7_once_each', function (): void {
    $tenant = stripeTenant('past_due', 'past_due', ['delinquent_since' => '2026-03-14 09:30:00+00']);
    $email = DB::connection('pgsql_platform')->selectOne('SELECT u.email FROM tenant_users tu JOIN users u ON u.id = tu.user_id WHERE tu.tenant_id = ?', [$tenant])->email;

    $this->artisan('saas-dunning:run')->assertSuccessful();
    $this->artisan('saas-dunning:run')->assertSuccessful();
    Notification::assertSentOnDemandTimes(PaymentReminder::class, 1);
    Notification::assertSentOnDemand(PaymentReminder::class, fn (PaymentReminder $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $email && $n->day === 1);

    $this->clock->advance('+3 days');   // day 4
    $this->artisan('saas-dunning:run')->assertSuccessful();
    $this->artisan('saas-dunning:run')->assertSuccessful();
    Notification::assertSentOnDemandTimes(PaymentReminder::class, 2);

    $this->clock->advance('+1 day');    // day 5: nothing new
    $this->artisan('saas-dunning:run')->assertSuccessful();
    Notification::assertSentOnDemandTimes(PaymentReminder::class, 2);

    $this->clock->advance('+2 days');   // day 7
    $this->artisan('saas-dunning:run')->assertSuccessful();
    $this->artisan('saas-dunning:run')->assertSuccessful();
    Notification::assertSentOnDemandTimes(PaymentReminder::class, 3);
    Notification::assertSentOnDemand(PaymentReminder::class, fn (PaymentReminder $n): bool => $n->day === 7);
});

it('trial_ending_notice_3_and_1_days_before', function (): void {
    stripeTenant('trial', 'trialing', ['trial_ends_at' => '2026-03-18 09:30:00+00']);

    $this->artisan('saas-dunning:run')->assertSuccessful();
    $this->artisan('saas-dunning:run')->assertSuccessful();
    Notification::assertSentOnDemandTimes(TrialEnding::class, 1);
    Notification::assertSentOnDemand(TrialEnding::class, fn (TrialEnding $n): bool => $n->daysLeft === 3);

    $this->clock->advance('+1 day');    // 2 days left: nothing new
    $this->artisan('saas-dunning:run')->assertSuccessful();
    Notification::assertSentOnDemandTimes(TrialEnding::class, 1);

    $this->clock->advance('+1 day');    // 1 day left
    $this->artisan('saas-dunning:run')->assertSuccessful();
    $this->artisan('saas-dunning:run')->assertSuccessful();
    Notification::assertSentOnDemandTimes(TrialEnding::class, 2);
    Notification::assertSentOnDemand(TrialEnding::class, fn (TrialEnding $n): bool => $n->daysLeft === 1);
});

it('dunning_ignores_tenants_that_are_not_delinquent_or_in_trial', function (): void {
    stripeTenant('active', 'active', ['delinquent_since' => '2026-03-10 00:00:00+00', 'trial_ends_at' => '2026-03-16 09:30:00+00']);

    $this->artisan('saas-dunning:run')->assertSuccessful();

    Notification::assertNothingSent();
});

it('the_webhook_route_sits_outside_the_tenant_api_and_is_throttled', function (): void {
    $route = app('router')->getRoutes()->match(Request::create('/api/stripe/webhook', 'POST'));

    expect($route->uri())->toBe('api/stripe/webhook')
        ->and($route->gatherMiddleware())->toContain('throttle:120,1')
        ->and($route->gatherMiddleware())->not->toContain('api');
});
