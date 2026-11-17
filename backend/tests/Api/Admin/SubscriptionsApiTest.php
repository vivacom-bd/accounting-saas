<?php

declare(strict_types=1);

use App\Contracts\BillingGateway;
use App\Domain\Admin\AdminRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApi;
use Tests\Support\BillingScenario;
use Tests\Support\FakeBillingGateway;

uses(AdminApi::class);

beforeEach(function (): void {
    config(['cashier.secret' => null]);
    $this->app->forgetInstance(BillingGateway::class);
});

/** @return array<string, mixed> */
function billingSubBody(array $over = []): array
{
    return ['plan_code' => 'pro', 'interval' => 'month', 'currency' => 'USD', 'collection' => 'offline', ...$over];
}

it('gates the subscription tab by billing abilities', function (): void {
    BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    $url = "/api/admin/tenants/{$tenant}/subscription";

    $this->getJson($url)->assertStatus(401);

    foreach ([AdminRole::Sales, AdminRole::Support, AdminRole::Engineer] as $role) {
        $this->asAdmin($role);
        $this->getJson($url)->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->getJson('/api/admin/billing/status')->assertStatus(403);
    }

    $this->asAdmin(AdminRole::Readonly);
    $this->getJson($url)->assertOk();
    $this->postJson($url, billingSubBody())->assertStatus(403)->assertJsonPath('code', 'forbidden');
    $this->postJson("{$url}/cancel", ['at_period_end' => true])->assertStatus(403);
    $this->postJson("{$url}/preview", ['plan_code' => 'pro'])->assertStatus(404)->assertJsonPath('code', 'subscription_not_found');   // a read: allowed, nothing to preview
});

it('reports whether Stripe is configured', function (): void {
    $this->asAdmin(AdminRole::Finance);
    $this->getJson('/api/admin/billing/status')->assertOk()->assertExactJson(['stripe_configured' => false]);

    $this->app->instance(BillingGateway::class, new FakeBillingGateway);
    $this->getJson('/api/admin/billing/status')->assertOk()->assertExactJson(['stripe_configured' => true]);
});

it('shows no subscription before one exists and 404s an unknown tenant', function (): void {
    $this->asAdmin(AdminRole::Finance);
    $tenant = BillingScenario::tenant();

    $this->getJson("/api/admin/tenants/{$tenant}/subscription")->assertOk()->assertJsonPath('data', null);
    $this->getJson('/api/admin/tenants/'.Str::uuid7().'/subscription')->assertStatus(404);
    $this->getJson('/api/admin/tenants/not-a-uuid/subscription')->assertStatus(404);
});

it('runs a subscription through its life over the API and audits each write', function (): void {
    BillingScenario::plan('pro');
    BillingScenario::plan('business', ['USD' => ['monthly' => 14900, 'annual' => 143000], 'KES' => ['monthly' => 1, 'annual' => 1], 'EUR' => ['monthly' => 1, 'annual' => 1]]);
    BillingScenario::coupon('WELCOME10');
    $tenant = BillingScenario::tenant(['lifecycle' => 'trial', 'trial_ends_at' => '2026-03-29 09:30:00+00']);
    $url = "/api/admin/tenants/{$tenant}/subscription";
    $this->asAdmin(AdminRole::Finance);

    $this->postJson($url, billingSubBody())->assertCreated()
        ->assertJsonPath('data.status', 'trialing')->assertJsonPath('data.plan_code', 'pro')->assertJsonPath('data.collection', 'offline')
        ->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.monthly_amount', 4900)->assertJsonPath('data.interval', 'month');
    $this->postJson($url, billingSubBody())->assertStatus(409)->assertJsonPath('code', 'subscription_exists');

    $this->postJson("{$url}/extend-trial", ['days' => 7, 'reason' => 'Evaluation running long'])->assertOk()
        ->assertJsonPath('data.trial_ends_at', fn ($v) => str_starts_with((string) $v, '2026-04-05'));
    $this->postJson("{$url}/apply-coupon", ['code' => 'welcome10'])->assertOk()->assertJsonPath('data.coupon_code', 'WELCOME10');
    $this->postJson("{$url}/preview", ['plan_code' => 'business'])->assertOk()
        ->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.amount_due', 14900);
    $this->postJson("{$url}/convert-trial", [])->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("{$url}/change-plan", ['plan_code' => 'business'])->assertOk()->assertJsonPath('data.plan_code', 'business')->assertJsonPath('data.monthly_amount', 14900);
    $this->postJson("{$url}/pause", ['until' => '2026-05-01'])->assertOk()->assertJsonPath('data.status', 'paused');
    $this->postJson("{$url}/resume", [])->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("{$url}/cancel", ['at_period_end' => true, 'reason' => 'Leaving'])->assertOk()->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.cancel_at', fn ($v) => $v !== null);
    $this->postJson("{$url}/cancel", ['at_period_end' => false])->assertOk()->assertJsonPath('data.status', 'cancelled');

    $actions = DB::connection('pgsql_platform')->table('admin_audit_events')->where('tenant_id', $tenant)->where('action', 'like', 'subscription.%')->pluck('action')->all();
    expect($actions)->toBe([
        'subscription.created', 'subscription.trial_extended', 'subscription.coupon_applied', 'subscription.trial_converted',
        'subscription.plan_changed', 'subscription.paused', 'subscription.resumed', 'subscription.cancel_scheduled', 'subscription.cancelled',
    ]);
});

it('refuses stripe collection with 503 when no keys are set, and still allows offline', function (): void {
    BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    $this->asAdmin(AdminRole::Finance);

    $this->postJson("/api/admin/tenants/{$tenant}/subscription", billingSubBody(['collection' => 'stripe']))
        ->assertStatus(503)->assertJsonPath('code', 'billing_not_configured');
    expect(DB::connection('pgsql_platform')->table('subscriptions')->count())->toBe(0);

    $this->postJson("/api/admin/tenants/{$tenant}/subscription", billingSubBody())->assertCreated();
});

it('validates the subscription request', function (): void {
    BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    $url = "/api/admin/tenants/{$tenant}/subscription";
    $this->asAdmin(AdminRole::Finance);

    $this->postJson($url, [])->assertStatus(422);
    $this->postJson($url, billingSubBody(['interval' => 'week']))->assertStatus(422);
    $this->postJson($url, billingSubBody(['currency' => 'GBP']))->assertStatus(422);
    $this->postJson($url, billingSubBody(['collection' => 'cheque']))->assertStatus(422);
    $this->postJson($url, billingSubBody(['custom_amount' => -5]))->assertStatus(422);
    $this->postJson($url, billingSubBody(['plan_code' => 'nope']))->assertStatus(422)->assertJsonPath('code', 'plan_not_found');
    $this->postJson("{$url}/extend-trial", ['days' => 0])->assertStatus(422);
    $this->postJson("{$url}/cancel", [])->assertStatus(422);
    $this->postJson("{$url}/pause", ['until' => 'someday'])->assertStatus(422);
});

it('answers 404 for a subscription action on a tenant without one', function (): void {
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    $this->asAdmin(AdminRole::Finance);

    $this->postJson("/api/admin/tenants/{$tenant}/subscription/cancel", ['at_period_end' => true])->assertStatus(404)->assertJsonPath('code', 'subscription_not_found');
});
