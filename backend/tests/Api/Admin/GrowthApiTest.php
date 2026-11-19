<?php

declare(strict_types=1);

use App\Domain\Admin\AdminRole;
use App\Notifications\Billing\TrialNudge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\AdminApi;
use Tests\Support\BillingScenario;

uses(AdminApi::class);

it('counts each stage of the funnel', function (): void {
    $a = BillingScenario::tenant(['lifecycle' => 'active']);
    BillingScenario::tenant(['lifecycle' => 'trial']);
    // Only one organisation has reached active, so the funnel reports one conversion.
    DB::connection('pgsql_platform')->table('tenant_lifecycle_events')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $a, 'from_status' => 'trial', 'to_status' => 'active',
        'reason' => 'test', 'actor_type' => 'system', 'occurred_at' => now(),
    ]);
    $this->asAdmin(AdminRole::Sales);

    $data = $this->getJson('/api/admin/growth/funnel')->assertOk()->json('data');

    expect($data['signups'])->toBe(2)
        ->and($data['converted'])->toBe(1)
        ->and($data['conversion_rate_bp'])->toBe(5000)
        ->and($data)->toHaveKeys(['signups', 'created_company', 'posted_first_journal', 'converted', 'conversion_rate_bp']);
});

it('closes a trial with a quoted amount and activates the organisation', function (): void {
    $version = BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant(['lifecycle' => 'trial', 'trial_ends_at' => '2026-03-29 09:30:00+00']);
    $this->asAdmin(AdminRole::Sales);

    $this->postJson("/api/admin/tenants/{$tenant}/convert-quote", [
        'plan_code' => 'pro', 'interval' => 'month', 'currency' => 'USD', 'custom_amount' => 3000, 'collection' => 'offline', 'note' => 'Negotiated',
    ])->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.custom_amount', 3000);

    $sub = DB::connection('pgsql_platform')->table('subscriptions')->where('tenant_id', $tenant)->first();
    expect($sub->plan_version_id)->toBe($version)
        ->and((int) $sub->custom_amount)->toBe(3000)
        ->and(DB::connection('pgsql_platform')->table('tenants')->where('id', $tenant)->value('lifecycle'))->toBe('active')
        ->and(DB::connection('pgsql_platform')->table('subscription_events')->where('tenant_id', $tenant)->count())->toBe(1);
});

it('lists current trials with activation flags', function (): void {
    BillingScenario::tenant(['lifecycle' => 'trial', 'trial_ends_at' => now()->addDays(5)]);
    $this->asAdmin(AdminRole::Sales);

    $rows = $this->getJson('/api/admin/growth/trials')->assertOk()->json('data');
    expect($rows)->not->toBe([])
        ->and($rows[0])->toHaveKeys(['id', 'name', 'days_left', 'has_company', 'has_posted', 'members', 'activation_score']);
});

it('sends trial nudges once and respects the switch', function (): void {
    Notification::fake();
    $tenant = BillingScenario::tenant(['lifecycle' => 'trial']);
    // Created three days ago: day-3 nudge is due.
    DB::connection('pgsql_platform')->table('tenants')->where('id', $tenant)->update(['created_at' => now()->subDays(3)]);

    $this->artisan('trial-nudges:send')->assertSuccessful();
    $this->artisan('trial-nudges:send')->assertSuccessful();
    Notification::assertSentOnDemandTimes(TrialNudge::class, 1);

    // Switched off: nothing more is sent.
    $this->asAdmin(AdminRole::Sales);
    $this->putJson('/api/admin/growth/nudges', ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
    $other = BillingScenario::tenant(['lifecycle' => 'trial']);
    DB::connection('pgsql_platform')->table('tenants')->where('id', $other)->update(['created_at' => now()->subDays(3)]);
    $this->artisan('trial-nudges:send')->assertSuccessful();
    Notification::assertSentOnDemandTimes(TrialNudge::class, 1);
});

it('gates growth on growth.manage', function (): void {
    $this->asAdmin(AdminRole::Support);
    $this->getJson('/api/admin/growth/funnel')->assertForbidden();
    $this->getJson('/api/admin/growth/trials')->assertForbidden();
});
