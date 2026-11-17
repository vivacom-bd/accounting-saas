<?php

declare(strict_types=1);

use App\Actions\Billing\ApplyCoupon;
use App\Actions\Billing\CancelSubscription;
use App\Actions\Billing\ChangePlan;
use App\Actions\Billing\ConvertTrial;
use App\Actions\Billing\ExtendTrial;
use App\Actions\Billing\PauseSubscription;
use App\Actions\Billing\PreviewPlanChange;
use App\Actions\Billing\ResumeSubscription;
use App\Actions\Billing\Subscribe;
use App\Contracts\BillingGateway;
use App\Domain\Shared\DomainRuleViolation;
use App\Services\Billing\NullBillingGateway;
use App\Services\Billing\StripeBillingGateway;
use App\Support\Problem\StatusMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BillingScenario;
use Tests\Support\FakeBillingGateway;

beforeEach(fn () => Notification::fake());

function billingSub(string $tenantId): object
{
    /** @var object */
    return DB::connection('pgsql_platform')->table('subscriptions')->where('tenant_id', $tenantId)->orderByDesc('created_at')->first();
}

/** @return list<object> */
function billingEvents(string $tenantId): array
{
    return DB::connection('pgsql_platform')->table('subscription_events')->where('tenant_id', $tenantId)->orderBy('occurred_at')->orderBy('id')->get()->all();
}

function billingTenantLifecycle(string $tenantId): string
{
    return (string) DB::connection('pgsql_platform')->table('tenants')->where('id', $tenantId)->value('lifecycle');
}

function billingViolation(Closure $fn): string
{
    try {
        $fn();
    } catch (DomainRuleViolation $e) {
        return $e->errorCode;
    }

    return 'none';
}

function billingAudit(string $tenantId): array
{
    return DB::connection('pgsql_platform')->table('admin_audit_events')->where('tenant_id', $tenantId)->orderBy('id')->pluck('action')->all();
}

/** @param  array<string, mixed>  $over */
function billingSubscribe(string $tenantId, array $over = []): object
{
    $a = $over + ['plan' => 'pro', 'interval' => 'month', 'currency' => 'USD', 'collection' => 'offline', 'custom' => null, 'coupon' => null];
    app(Subscribe::class)(BillingScenario::admin(), $tenantId, $a['plan'], $a['interval'], $a['currency'], $a['collection'], $a['custom'], $a['coupon']);

    return billingSub($tenantId);
}

it('offline_subscription_converts_trial_to_active', function (): void {
    $fake = new FakeBillingGateway;
    $this->app->instance(BillingGateway::class, $fake);
    BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant(['lifecycle' => 'trial', 'trial_ends_at' => '2026-03-29 09:30:00+00']);

    $sub = billingSubscribe($tenant);
    expect($sub->status)->toBe('trialing')
        ->and($sub->collection)->toBe('offline')
        ->and($sub->stripe_subscription_id)->toBeNull()
        ->and(billingEvents($tenant))->toBe([]);   // a trial carries no MRR yet

    app(ConvertTrial::class)(BillingScenario::admin(), $tenant, 'Signed the order form');

    $sub = billingSub($tenant);
    expect($sub->status)->toBe('active')
        ->and((new DateTimeImmutable($sub->current_period_end))->format('Y-m-d H:i'))->toBe('2026-04-15 09:30')
        ->and(billingTenantLifecycle($tenant))->toBe('active');
    $events = billingEvents($tenant);
    expect($events)->toHaveCount(1)
        ->and([$events[0]->kind, $events[0]->currency, $events[0]->mrr_delta])->toBe(['new', 'USD', 4900])
        ->and(billingAudit($tenant))->toContain('subscription.created', 'subscription.trial_converted', 'tenant.lifecycle_changed')
        ->and($fake->calls)->toBe([]);          // offline: no gateway call at all
});

it('stripe_subscription_uses_gateway_and_mirrors_state', function (): void {
    $fake = new FakeBillingGateway;
    $this->app->instance(BillingGateway::class, $fake);
    $version = BillingScenario::plan('pro');
    DB::connection('pgsql_platform')->table('stripe_prices')->insert([
        'id' => (string) Str::uuid7(), 'plan_version_id' => $version, 'currency' => 'USD', 'interval' => 'month', 'amount' => 4900,
        'stripe_price_id' => 'price_pro_usd_month', 'created_at' => now(),
    ]);
    $tenant = BillingScenario::tenant(['lifecycle' => 'trial', 'trial_ends_at' => '2026-03-29 09:30:00+00']);

    $sub = billingSubscribe($tenant, ['collection' => 'stripe']);

    expect($fake->callsTo('ensureCustomer'))->toHaveCount(1)
        ->and($fake->callsTo('createSubscription')[0]['priceId'])->toBe('price_pro_usd_month')
        ->and($fake->callsTo('createSubscription')[0]['trialEnd']->format('Y-m-d H:i'))->toBe('2026-03-29 09:30')
        ->and($sub->collection)->toBe('stripe')
        ->and($sub->status)->toBe('trialing')
        ->and($sub->stripe_subscription_id)->toBe('sub_fake_1')
        ->and($sub->stripe_customer_id)->toStartWith('cus_');

    app(CancelSubscription::class)(BillingScenario::admin(), $tenant, true, 'Customer asked');
    $sub = billingSub($tenant);
    expect($fake->callsTo('cancel'))->toHaveCount(1)
        ->and($fake->callsTo('cancel')[0]['atPeriodEnd'])->toBeTrue()
        ->and((new DateTimeImmutable($sub->cancel_at))->format('Y-m-d H:i'))->toBe('2026-04-15 09:30')
        ->and($sub->status)->toBe('trialing');
});

it('stripe_collection_needs_the_synced_price', function (): void {
    $this->app->instance(BillingGateway::class, new FakeBillingGateway);
    BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant();

    expect(billingViolation(fn () => billingSubscribe($tenant, ['collection' => 'stripe'])))->toBe('billing_price_not_synced')
        ->and(DB::connection('pgsql_platform')->table('subscriptions')->count())->toBe(0);
});

it('without_keys_stripe_collection_is_refused_503', function (): void {
    $this->app->instance(BillingGateway::class, new NullBillingGateway);
    BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant();

    $code = billingViolation(fn () => billingSubscribe($tenant, ['collection' => 'stripe']));
    expect($code)->toBe('billing_not_configured')
        ->and(StatusMap::status($code))->toBe(503)
        ->and(DB::connection('pgsql_platform')->table('subscriptions')->count())->toBe(0);

    // Offline collection never touches the gateway.
    expect(billingSubscribe($tenant)->collection)->toBe('offline');
});

it('the gateway is Null without a Stripe secret and Stripe with one', function (): void {
    config(['cashier.secret' => null]);
    $this->app->forgetInstance(BillingGateway::class);
    expect(app(BillingGateway::class))->toBeInstanceOf(NullBillingGateway::class)
        ->and(app(BillingGateway::class)->isConfigured())->toBeFalse();

    config(['cashier.secret' => 'sk_test_not_a_real_key']);
    $this->app->forgetInstance(BillingGateway::class);
    expect(app(BillingGateway::class))->toBeInstanceOf(StripeBillingGateway::class)
        ->and(app(BillingGateway::class)->isConfigured())->toBeTrue();
});

it('plan_change_records_expansion_mrr_event', function (): void {
    BillingScenario::plan('pro');
    BillingScenario::plan('business', ['USD' => ['monthly' => 14900, 'annual' => 143000], 'KES' => ['monthly' => 1, 'annual' => 1], 'EUR' => ['monthly' => 1, 'annual' => 1]]);
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    billingSubscribe($tenant);

    app(ChangePlan::class)(BillingScenario::admin(), $tenant, 'business');
    app(ChangePlan::class)(BillingScenario::admin(), $tenant, 'pro');

    expect(array_map(fn ($e) => [$e->kind, $e->mrr_delta], billingEvents($tenant)))->toBe([
        ['new', 4900], ['expansion', 10000], ['contraction', -10000],
    ]);

    // Same plan again: nothing moves, nothing is recorded.
    app(ChangePlan::class)(BillingScenario::admin(), $tenant, 'pro');
    expect(billingEvents($tenant))->toHaveCount(3);
});

it('plan change calls the gateway for stripe collection and mirrors the answer', function (): void {
    $fake = new FakeBillingGateway;
    $this->app->instance(BillingGateway::class, $fake);
    $pro = BillingScenario::plan('pro');
    $biz = BillingScenario::plan('business', ['USD' => ['monthly' => 14900, 'annual' => 143000], 'KES' => ['monthly' => 1, 'annual' => 1], 'EUR' => ['monthly' => 1, 'annual' => 1]]);
    foreach ([[$pro, 'price_pro', 4900], [$biz, 'price_biz', 14900]] as [$v, $price, $amount]) {
        DB::connection('pgsql_platform')->table('stripe_prices')->insert(['id' => (string) Str::uuid7(), 'plan_version_id' => $v, 'currency' => 'USD', 'interval' => 'month', 'amount' => $amount, 'stripe_price_id' => $price, 'created_at' => now()]);
    }
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    billingSubscribe($tenant, ['collection' => 'stripe']);

    app(ChangePlan::class)(BillingScenario::admin(), $tenant, 'business', null, null, false);

    expect($fake->callsTo('changePrice'))->toHaveCount(1)
        ->and($fake->callsTo('changePrice')[0]['priceId'])->toBe('price_biz')
        ->and($fake->callsTo('changePrice')[0]['prorate'])->toBeFalse()
        ->and(billingSub($tenant)->plan_version_id)->toBe($biz);

    $preview = app(PreviewPlanChange::class)($tenant, 'pro');
    expect($preview)->toBe(['currency' => 'USD', 'amount_due' => 1234, 'lines' => [['description' => 'Proration', 'amount' => 1234]]]);
});

it('annual_plan_mrr_is_one_twelfth', function (): void {
    BillingScenario::plan('pro');
    $annual = BillingScenario::tenant(['lifecycle' => 'active']);
    $custom = BillingScenario::tenant(['lifecycle' => 'active']);

    billingSubscribe($annual, ['interval' => 'year']);                          // 47000 / 12 = 3916.67 -> 3917
    billingSubscribe($custom, ['interval' => 'year', 'custom' => 60000]);       // custom amount wins: 60000 / 12

    expect(billingEvents($annual)[0]->mrr_delta)->toBe(3917)
        ->and(billingEvents($custom)[0]->mrr_delta)->toBe(5000)
        ->and(billingSub($custom)->custom_amount)->toBe(60000)
        ->and((new DateTimeImmutable(billingSub($annual)->current_period_end))->format('Y-m-d'))->toBe('2027-03-15');
});

it('keeps each currency separate and refuses a currency the plan has no price in', function (): void {
    BillingScenario::plan('pro', ['USD' => ['monthly' => 4900, 'annual' => 47000], 'KES' => ['monthly' => null, 'annual' => null], 'EUR' => ['monthly' => 4500, 'annual' => 43200]]);
    $kes = BillingScenario::tenant(['lifecycle' => 'active']);
    $eur = BillingScenario::tenant(['lifecycle' => 'active']);

    expect(billingViolation(fn () => billingSubscribe($kes, ['currency' => 'KES'])))->toBe('subscription_price_missing');
    expect(billingSubscribe($kes, ['currency' => 'KES', 'custom' => 500000])->currency)->toBe('KES');
    billingSubscribe($eur, ['currency' => 'EUR']);
    expect([billingEvents($kes)[0]->currency, billingEvents($eur)[0]->currency])->toBe(['KES', 'EUR']);
});

it('allows one live subscription per tenant and treats a second one after cancelling as reactivation', function (): void {
    BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    billingSubscribe($tenant);

    expect(billingViolation(fn () => billingSubscribe($tenant)))->toBe('subscription_exists');

    app(CancelSubscription::class)(BillingScenario::admin(), $tenant, false, 'Closing');
    expect(billingSub($tenant)->status)->toBe('cancelled')
        ->and(billingTenantLifecycle($tenant))->toBe('cancelled');

    billingSubscribe($tenant);
    expect(array_map(fn ($e) => [$e->kind, $e->mrr_delta], billingEvents($tenant)))->toBe([['new', 4900], ['churn', -4900], ['reactivation', 4900]])
        ->and(billingTenantLifecycle($tenant))->toBe('active')
        ->and(DB::connection('pgsql_platform')->table('subscriptions')->where('tenant_id', $tenant)->count())->toBe(2);
});

it('extend_trial_moves_trial_end_and_lifecycle_stays_trial', function (): void {
    BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant(['lifecycle' => 'trial', 'trial_ends_at' => '2026-03-29 09:30:00+00']);
    billingSubscribe($tenant);

    app(ExtendTrial::class)(BillingScenario::admin(), $tenant, 7, 'Needs more time to evaluate');

    $tenantRow = DB::connection('pgsql_platform')->table('tenants')->where('id', $tenant)->first();
    $sub = billingSub($tenant);
    expect((new DateTimeImmutable($tenantRow->trial_ends_at))->format('Y-m-d H:i'))->toBe('2026-04-05 09:30')
        ->and((new DateTimeImmutable($sub->trial_ends_at))->format('Y-m-d H:i'))->toBe('2026-04-05 09:30')
        ->and((new DateTimeImmutable($sub->current_period_end))->format('Y-m-d H:i'))->toBe('2026-04-05 09:30')
        ->and($sub->status)->toBe('trialing')
        ->and($tenantRow->lifecycle)->toBe('trial')
        ->and(billingAudit($tenant))->toContain('subscription.trial_extended');

    expect(billingViolation(fn () => app(ExtendTrial::class)(BillingScenario::admin(), $tenant, 0)))->toBe('trial_extension_invalid');

    $active = BillingScenario::tenant(['lifecycle' => 'active']);
    billingSubscribe($active);
    expect(billingViolation(fn () => app(ExtendTrial::class)(BillingScenario::admin(), $active, 7)))->toBe('trial_not_active');
});

it('cancel_at_period_end_keeps_active_until_period_end', function (): void {
    BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    billingSubscribe($tenant);

    app(CancelSubscription::class)(BillingScenario::admin(), $tenant, true, 'Switching vendor');
    $sub = billingSub($tenant);
    expect($sub->status)->toBe('active')
        ->and((new DateTimeImmutable($sub->cancel_at))->format('Y-m-d H:i'))->toBe('2026-04-15 09:30')
        ->and(billingTenantLifecycle($tenant))->toBe('active')
        ->and(billingEvents($tenant))->toHaveCount(1);       // no churn yet

    // Before the period ends the daily step leaves it alone.
    $this->artisan('subscriptions:advance')->assertExitCode(0);
    expect(billingSub($tenant)->status)->toBe('active');

    // Resuming before the end withdraws the cancellation.
    app(ResumeSubscription::class)(BillingScenario::admin(), $tenant);
    expect(billingSub($tenant)->cancel_at)->toBeNull();
    app(CancelSubscription::class)(BillingScenario::admin(), $tenant, true, 'Again');

    $this->clock->advance('+31 days');
    $this->artisan('subscriptions:advance')->assertExitCode(0);

    $sub = billingSub($tenant);
    expect($sub->status)->toBe('cancelled')
        ->and(billingTenantLifecycle($tenant))->toBe('cancelled')
        ->and(array_map(fn ($e) => [$e->kind, $e->mrr_delta], billingEvents($tenant)))->toBe([['new', 4900], ['churn', -4900]])
        ->and((new DateTimeImmutable(billingEvents($tenant)[1]->occurred_at))->format('Y-m-d H:i'))->toBe('2026-04-15 09:30')
        ->and(DB::connection('pgsql_platform')->table('tenant_deletions')->where('tenant_id', $tenant)->count())->toBe(1);

    // Idempotent.
    $this->artisan('subscriptions:advance')->assertExitCode(0);
    expect(billingEvents($tenant))->toHaveCount(2);
});

it('rolls an offline subscription into its next period and wakes a paused one', function (): void {
    BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    $paused = BillingScenario::tenant(['lifecycle' => 'active']);
    billingSubscribe($tenant);
    billingSubscribe($paused);
    app(PauseSubscription::class)(BillingScenario::admin(), $paused, new DateTimeImmutable('2026-04-01 00:00:00+00'), 'Seasonal');
    expect(billingSub($paused)->status)->toBe('paused');

    $this->clock->advance('+50 days');   // 2026-05-04
    $this->artisan('subscriptions:advance')->assertExitCode(0);

    expect((new DateTimeImmutable(billingSub($tenant)->current_period_end))->format('Y-m-d H:i'))->toBe('2026-05-15 09:30')
        ->and(billingSub($tenant)->status)->toBe('active')
        ->and(billingSub($paused)->status)->toBe('active')
        ->and(billingSub($paused)->pause_until)->toBeNull()
        ->and(billingEvents($tenant))->toHaveCount(1);
});

it('pauses and resumes, mirroring the gateway for stripe collection', function (): void {
    $fake = new FakeBillingGateway;
    $this->app->instance(BillingGateway::class, $fake);
    $version = BillingScenario::plan('pro');
    DB::connection('pgsql_platform')->table('stripe_prices')->insert(['id' => (string) Str::uuid7(), 'plan_version_id' => $version, 'currency' => 'USD', 'interval' => 'month', 'amount' => 4900, 'stripe_price_id' => 'price_pro', 'created_at' => now()]);
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    billingSubscribe($tenant, ['collection' => 'stripe']);

    app(PauseSubscription::class)(BillingScenario::admin(), $tenant, null);
    expect(billingSub($tenant)->status)->toBe('paused')->and($fake->callsTo('pause'))->toHaveCount(1);

    app(ResumeSubscription::class)(BillingScenario::admin(), $tenant);
    expect(billingSub($tenant)->status)->toBe('active')->and($fake->callsTo('resume'))->toHaveCount(1);

    expect(billingViolation(fn () => app(ResumeSubscription::class)(BillingScenario::admin(), $tenant)))->toBe('subscription_not_resumable');
});

it('applies a coupon only when it is valid for the subscription', function (): void {
    BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    billingSubscribe($tenant);
    $apply = fn (string $code) => app(ApplyCoupon::class)(BillingScenario::admin(), $tenant, $code);

    expect(billingViolation(fn () => $apply('NOPE')))->toBe('coupon_not_found');

    BillingScenario::coupon('OLD', ['expires_at' => '2026-03-01 00:00:00+00']);
    BillingScenario::coupon('OFF', ['is_active' => false]);
    BillingScenario::coupon('USEDUP', ['max_redemptions' => 2, 'redeemed_count' => 2]);
    BillingScenario::coupon('EURONLY', ['percent' => null, 'amount' => 500, 'currency' => 'EUR']);
    BillingScenario::coupon('OTHERPLAN', ['restrictions' => json_encode(['plan_codes' => ['business']])]);
    expect(billingViolation(fn () => $apply('OLD')))->toBe('coupon_expired')
        ->and(billingViolation(fn () => $apply('OFF')))->toBe('coupon_inactive')
        ->and(billingViolation(fn () => $apply('USEDUP')))->toBe('coupon_exhausted')
        ->and(billingViolation(fn () => $apply('EURONLY')))->toBe('coupon_currency_mismatch')
        ->and(billingViolation(fn () => $apply('OTHERPLAN')))->toBe('coupon_not_applicable');

    $couponId = BillingScenario::coupon('GOOD', ['max_redemptions' => 3, 'redeemed_count' => 1]);
    $apply('good');   // codes are case-insensitive
    expect(billingSub($tenant)->coupon_id)->toBe($couponId)
        ->and((int) DB::connection('pgsql_platform')->table('coupons')->where('id', $couponId)->value('redeemed_count'))->toBe(2)
        ->and(billingAudit($tenant))->toContain('subscription.coupon_applied');

    BillingScenario::coupon('SECOND');
    expect(billingViolation(fn () => $apply('SECOND')))->toBe('coupon_already_applied');
});

it('applies a coupon through the gateway for stripe collection and needs it synced', function (): void {
    $fake = new FakeBillingGateway;
    $this->app->instance(BillingGateway::class, $fake);
    $version = BillingScenario::plan('pro');
    DB::connection('pgsql_platform')->table('stripe_prices')->insert(['id' => (string) Str::uuid7(), 'plan_version_id' => $version, 'currency' => 'USD', 'interval' => 'month', 'amount' => 4900, 'stripe_price_id' => 'price_pro', 'created_at' => now()]);
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    billingSubscribe($tenant, ['collection' => 'stripe']);

    BillingScenario::coupon('LOCAL');
    expect(billingViolation(fn () => app(ApplyCoupon::class)(BillingScenario::admin(), $tenant, 'LOCAL')))->toBe('coupon_not_synced');

    BillingScenario::coupon('SYNCED', ['stripe_coupon_id' => 'SYNCED']);
    app(ApplyCoupon::class)(BillingScenario::admin(), $tenant, 'SYNCED');
    expect($fake->callsTo('applyCoupon'))->toHaveCount(1)->and($fake->callsTo('applyCoupon')[0]['couponId'])->toBe('SYNCED');
});

it('takes a coupon code at subscribe time', function (): void {
    BillingScenario::plan('pro');
    BillingScenario::coupon('WELCOME10');
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);

    $sub = billingSubscribe($tenant, ['coupon' => 'WELCOME10']);

    expect($sub->coupon_id)->not->toBeNull()
        ->and((int) DB::connection('pgsql_platform')->table('coupons')->where('code', 'WELCOME10')->value('redeemed_count'))->toBe(1);
});

it('sync_command_stores_ids_and_is_idempotent', function (): void {
    $fake = new FakeBillingGateway;
    $this->app->instance(BillingGateway::class, $fake);
    $version = BillingScenario::plan('pro', ['USD' => ['monthly' => 4900, 'annual' => 47000], 'KES' => ['monthly' => null, 'annual' => null], 'EUR' => ['monthly' => 4500, 'annual' => 43200]]);
    BillingScenario::coupon('WELCOME10');
    BillingScenario::coupon('OLDONE', ['is_active' => false]);

    $this->artisan('stripe:sync-catalogue')->assertExitCode(0);

    $db = DB::connection('pgsql_platform');
    expect($db->table('plan_versions')->where('id', $version)->value('stripe_product_id'))->toBe('prod_pro-v1')
        ->and($db->table('stripe_prices')->where('plan_version_id', $version)->count())->toBe(4)   // KES has no price: skipped
        ->and($db->table('stripe_prices')->where('plan_version_id', $version)->where('currency', 'USD')->where('interval', 'year')->value('stripe_price_id'))->toBe('price_prod_pro-v1_USD_year')
        ->and($db->table('coupons')->where('code', 'WELCOME10')->value('stripe_coupon_id'))->toBe('coupon_WELCOME10')
        ->and($db->table('coupons')->where('code', 'OLDONE')->value('stripe_coupon_id'))->toBeNull()
        ->and($fake->callsTo('upsertProduct'))->toHaveCount(1)
        ->and($fake->callsTo('upsertPrice'))->toHaveCount(4)
        ->and($fake->callsTo('upsertCoupon'))->toHaveCount(1);

    $calls = count($fake->calls);
    $this->artisan('stripe:sync-catalogue')->assertExitCode(0);

    expect(count($fake->calls))->toBe($calls)
        ->and($db->table('stripe_prices')->count())->toBe(4);
});

it('sync_command_refuses_clearly_when_not_configured', function (): void {
    $this->app->instance(BillingGateway::class, new NullBillingGateway);
    BillingScenario::plan('pro');

    $this->artisan('stripe:sync-catalogue')
        ->expectsOutputToContain('Stripe is not configured')
        ->assertExitCode(1);

    expect(DB::connection('pgsql_platform')->table('stripe_prices')->count())->toBe(0);
});
