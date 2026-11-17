<?php

declare(strict_types=1);

use App\Domain\Admin\AdminRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApi;

uses(AdminApi::class);

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function planBody(array $over = []): array
{
    return [
        'code' => 'starter', 'name' => 'Starter', 'description' => 'Essentials', 'sort' => 1,
        'prices' => ['USD' => ['monthly' => 4900, 'annual' => 47000], 'KES' => ['monthly' => 640000, 'annual' => 6140000], 'EUR' => ['monthly' => 4500, 'annual' => 43200]],
        'limits' => ['companies' => 1, 'users' => 3, 'api_clients' => 1, 'journal_lines_per_month' => 1000, 'storage_mb' => 2048, 'api_calls_per_day' => 500],
        'entitlements' => ['multi_currency' => false, 'independent_approvals' => false, 'fixed_assets' => false, 'budgets' => false,
            'api_access' => true, 'consolidation' => false, 'audit_chain_export' => true, 'custom_roles' => false],
        'trial_days' => 14, ...$over,
    ];
}

function auditCount(string $action): int
{
    return DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', $action)->count();
}

it('requires a signed-in admin and the right ability', function (): void {
    $this->getJson('/api/admin/plans')->assertStatus(401);

    $this->asAdmin(AdminRole::Sales);
    $this->getJson('/api/admin/plans')->assertStatus(403)->assertJsonPath('code', 'forbidden');
    $this->postJson('/api/admin/plans', planBody())->assertStatus(403);
    $this->getJson('/api/admin/coupons')->assertOk();
});

it('lets support see neither plans nor coupons', function (): void {
    $this->asAdmin(AdminRole::Support);

    $this->getJson('/api/admin/plans')->assertStatus(403);
    $this->getJson('/api/admin/coupons')->assertStatus(403);
});

it('creates, lists and versions a plan', function (): void {
    $this->asAdmin(AdminRole::Finance);

    $id = $this->postJson('/api/admin/plans', planBody())->assertCreated()
        ->assertJsonPath('data.code', 'starter')->assertJsonPath('data.current_version.version', 1)
        ->assertJsonPath('data.current_version.prices.USD.monthly', 4900)->json('data.id');

    $future = now()->addDays(20)->toDateString();
    $this->postJson("/api/admin/plans/{$id}/versions", [
        'prices' => planBody()['prices'], 'limits' => ['users' => 5] + planBody()['limits'], 'entitlements' => planBody()['entitlements'],
        'trial_days' => 14, 'effective_from' => $future,
    ])->assertCreated()->assertJsonPath('data.version', 2);

    $row = $this->getJson('/api/admin/plans')->assertOk()->json('data.0');
    expect($row['code'])->toBe('starter')
        ->and($row['current_version']['version'])->toBe(1)
        ->and($row['scheduled_versions'])->toHaveCount(1)
        ->and($row['scheduled_versions'][0]['version'])->toBe(2)
        ->and($row['scheduled_versions'][0]['limits']['users'])->toBe(5)
        ->and($row['tenant_count'])->toBe(0);

    expect(auditCount('plan.created'))->toBe(1)->and(auditCount('plan.version_published'))->toBe(1);
});

it('counts tenants on a plan', function (): void {
    $this->asAdmin(AdminRole::Finance);
    $this->postJson('/api/admin/plans', planBody())->assertCreated();
    $versionId = $this->getJson('/api/admin/plans')->json('data.0.current_version.id');
    DB::connection('pgsql_owner')->table('tenants')->insert([
        'id' => (string) Str::uuid7(), 'name' => 'Acme', 'slug' => 'acme', 'settings' => json_encode(['plan_version_id' => $versionId]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->getJson('/api/admin/plans')->assertJsonPath('data.0.tenant_count', 1);
});

it('answers 422 for bad prices, limits and past dates', function (): void {
    $this->asAdmin(AdminRole::Owner);

    $this->postJson('/api/admin/plans', planBody(['prices' => ['GBP' => ['monthly' => 1, 'annual' => 1]]]))->assertStatus(422)->assertJsonPath('code', 'plan_prices_invalid');
    $this->postJson('/api/admin/plans', planBody(['prices' => ['USD' => ['monthly' => 49.5, 'annual' => 1]]]))->assertStatus(422);
    $this->postJson('/api/admin/plans', planBody(['limits' => ['companies' => 1]]))->assertStatus(422)->assertJsonPath('code', 'plan_limits_invalid');
    $this->postJson('/api/admin/plans', planBody(['code' => 'Bad Code']))->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    $id = $this->postJson('/api/admin/plans', planBody())->assertCreated()->json('data.id');
    $this->postJson('/api/admin/plans', planBody())->assertStatus(409)->assertJsonPath('code', 'plan_code_taken');
    $this->postJson("/api/admin/plans/{$id}/versions", [
        'prices' => planBody()['prices'], 'limits' => planBody()['limits'], 'entitlements' => planBody()['entitlements'],
        'trial_days' => 14, 'effective_from' => '2026-03-10', // the test clock is frozen at 2026-03-15
    ])->assertStatus(422)->assertJsonPath('code', 'plan_version_date_invalid');
    $this->postJson('/api/admin/plans/'.Str::uuid7().'/versions', planBody())->assertStatus(404);
});

it('archives a plan', function (): void {
    $this->asAdmin(AdminRole::Finance);
    $id = $this->postJson('/api/admin/plans', planBody())->assertCreated()->json('data.id');

    $this->postJson("/api/admin/plans/{$id}/archive")->assertOk()->assertJsonPath('data.is_active', false);

    expect(auditCount('plan.archived'))->toBe(1);
});

it('upserts and lists add-ons', function (): void {
    $this->asAdmin(AdminRole::Finance);
    $body = ['name' => 'Extra company', 'kind' => 'company', 'price' => ['USD' => 2500, 'KES' => 325000, 'EUR' => 2300], 'unit_label' => 'company / month'];

    $this->putJson('/api/admin/addons/extra_company', $body)->assertOk()->assertJsonPath('data.code', 'extra_company');
    $this->putJson('/api/admin/addons/extra_company', ['name' => 'Extra entity'] + $body)->assertOk()->assertJsonPath('data.name', 'Extra entity');
    $this->putJson('/api/admin/addons/extra_company', ['kind' => 'seat'] + $body)->assertStatus(422);

    $this->getJson('/api/admin/addons')->assertOk()->assertJsonCount(1, 'data');
    expect(auditCount('addon.created'))->toBe(1)->and(auditCount('addon.updated'))->toBe(1);
});

it('creates, lists and archives coupons', function (): void {
    $this->asAdmin(AdminRole::Sales);
    $body = ['code' => 'launch20', 'percent' => 20, 'duration' => 'repeating', 'duration_months' => 3, 'max_redemptions' => 100,
        'expires_at' => now()->addMonth()->toIso8601String(), 'restrictions' => ['plan_codes' => ['starter'], 'new_customers_only' => true]];

    $id = $this->postJson('/api/admin/coupons', $body)->assertCreated()
        ->assertJsonPath('data.code', 'LAUNCH20')->assertJsonPath('data.percent', 20)->assertJsonPath('data.amount', null)->json('data.id');
    $this->postJson('/api/admin/coupons', ['code' => 'BOTH', 'amount' => 500, 'currency' => 'USD'] + $body)->assertStatus(422)->assertJsonPath('code', 'coupon_discount_invalid');
    $this->postJson('/api/admin/coupons', $body)->assertStatus(409)->assertJsonPath('code', 'coupon_code_taken');

    $this->getJson('/api/admin/coupons')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', true);
    $this->postJson("/api/admin/coupons/{$id}/archive")->assertOk()->assertJsonPath('data.is_active', false);

    expect(auditCount('coupon.created'))->toBe(1)->and(auditCount('coupon.archived'))->toBe(1);
});

it('keeps the tenant app role away from the catalogue', function (): void {
    foreach (['plans', 'plan_versions', 'plan_addons', 'coupons'] as $table) {
        try {
            DB::connection('pgsql')->table($table)->count();
            $state = null;
        } catch (QueryException $e) {
            $state = $e->getCode();
        }
        expect($state)->toBe('42501');
    }
});
