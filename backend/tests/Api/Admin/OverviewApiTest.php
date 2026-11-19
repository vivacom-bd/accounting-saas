<?php

declare(strict_types=1);

use App\Domain\Admin\AdminRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AdminApi;
use Tests\Support\BillingScenario;

uses(AdminApi::class);

/** A live subscription row on the given plan version. */
function ovSub(string $tenant, string $version, array $over = []): void
{
    DB::connection('pgsql_platform')->table('subscriptions')->insert($over + [
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'plan_version_id' => $version, 'collection' => 'offline',
        'interval' => 'month', 'currency' => 'USD', 'quantity' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('reports mrr per currency and never a blended total', function (): void {
    $usd = BillingScenario::plan('pro');                 // USD monthly 4900
    $kes = BillingScenario::plan('pro-kes', ['USD' => ['monthly' => null, 'annual' => null], 'KES' => ['monthly' => 640000, 'annual' => 6000000], 'EUR' => ['monthly' => null, 'annual' => null]]);
    ovSub(BillingScenario::tenant(['lifecycle' => 'active']), $usd);
    ovSub(BillingScenario::tenant(['lifecycle' => 'active']), $kes, ['currency' => 'KES']);

    $this->asAdmin(AdminRole::Finance);
    $data = $this->getJson('/api/admin/overview')->assertOk()->json('data');

    $byCurrency = collect($data['mrr'])->keyBy('currency');
    expect($byCurrency['USD']['amount'])->toBe(4900)
        ->and($byCurrency['KES']['amount'])->toBe(640000)
        ->and($data)->not->toHaveKey('mrr_total');
});

it('normalises annual and custom amounts to monthly', function (): void {
    $v = BillingScenario::plan('pro');                    // USD annual 47000
    // Annual standard price: 47000 / 12 = 3916.67 -> 3917.
    ovSub(BillingScenario::tenant(['lifecycle' => 'active']), $v, ['interval' => 'year']);
    // Annual with a custom amount of 60000: 60000 / 12 = 5000.
    ovSub(BillingScenario::tenant(['lifecycle' => 'active']), $v, ['interval' => 'year', 'custom_amount' => 60000]);

    $this->asAdmin(AdminRole::Readonly);
    $mrr = collect($this->getJson('/api/admin/overview')->assertOk()->json('data.mrr'));
    expect($mrr->firstWhere('currency', 'USD')['amount'])->toBe(3917 + 5000);
});

it('counts cohort retention for tenants not yet cancelled', function (): void {
    $active = BillingScenario::tenant(['lifecycle' => 'active']);
    $gone = BillingScenario::tenant(['lifecycle' => 'cancelled']);
    DB::connection('pgsql_platform')->table('tenant_lifecycle_events')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $gone, 'from_status' => 'active', 'to_status' => 'cancelled',
        'reason' => 'test', 'actor_type' => 'system', 'occurred_at' => now()->subMinute(),
    ]);
    expect($active)->not->toBe($gone);

    $this->asAdmin(AdminRole::Finance);
    $cohorts = collect($this->getJson('/api/admin/overview')->assertOk()->json('data.cohorts'));

    $row = $cohorts->firstWhere('cohort_month', now()->format('Y-m'));
    expect($row)->not->toBeNull()
        ->and($row['size'])->toBeGreaterThanOrEqual(1)
        ->and($row['retained'][0])->toBe($row['size']);
});

it('gates the overview on billing.view', function (): void {
    $this->asAdmin(AdminRole::Support);
    $this->getJson('/api/admin/overview')->assertForbidden();
});
