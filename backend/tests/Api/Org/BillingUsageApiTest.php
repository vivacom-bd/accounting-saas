<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/* GET /org/billing/usage and API-call metering (plan Task 10). */

beforeEach(function (): void {
    $this->setUpTrade();
    $this->admin = $this->person('Ada Admin', [], org: 'admin');
});

function apiCallsToday(): int
{
    return (int) DB::connection('pgsql_owner')->table('usage_daily')->where('metric', 'api_calls')->sum('value');
}

it('shows the plan and meters to organisation admins only', function (): void {
    $this->asPerson($this->admin)->getJson('/api/v1/org/billing/usage')->assertOk()
        ->assertJsonStructure(['data' => ['plan_code', 'meters' => [['key', 'limit', 'used', 'ratio', 'level']]]])
        ->assertJsonPath('data.meters.0.key', 'companies')
        ->assertJsonPath('data.meters.0.used', 1);

    $member = $this->person('Mia Member', ['Accountant']);
    $this->asPerson($member)->getJson('/api/v1/org/billing/usage')->assertForbidden();
});

it('refuses tokens on the usage route', function (): void {
    $token = $this->newToken(['contacts:read']);
    $this->asToken($token)->getJson('/api/v1/org/billing/usage')->assertForbidden();
});

it('api_call_is_metered_for_tokens_not_sessions', function (): void {
    $token = $this->newToken(['contacts:read']);

    $this->asPerson($this->person('Sam Accountant', ['Accountant']))->getJson($this->api('contacts'))->assertOk();
    expect(apiCallsToday())->toBe(0);

    $this->asToken($token)->getJson($this->api('contacts'))->assertOk();
    $this->getJson($this->api('contacts'))->assertOk();
    expect(apiCallsToday())->toBe(2);

    $this->asToken('999|bogus')->getJson($this->api('contacts'))->assertUnauthorized();
    expect(apiCallsToday())->toBe(2);
});

it('answers plan_limit_reached as a 402 problem with the limit in meta', function (): void {
    $planId = (string) Str::uuid7();
    $platform = DB::connection('pgsql_platform');
    $platform->table('plans')->insert(['id' => $planId, 'code' => 'tiny', 'name' => 'Tiny', 'created_at' => now(), 'updated_at' => now()]);
    $versionId = (string) Str::uuid7();
    $platform->table('plan_versions')->insert(['id' => $versionId, 'plan_id' => $planId, 'version' => 1, 'prices' => '{}', 'entitlements' => '{}', 'effective_from' => '2026-01-01T00:00:00Z',
        'limits' => json_encode(['companies' => 1, 'users' => null, 'api_clients' => null, 'journal_lines_per_month' => null, 'storage_mb' => null, 'api_calls_per_day' => null])]);
    DB::connection('pgsql_owner')->table('tenants')->where('id', $this->f->tenantId)->update(['settings' => json_encode(['plan_version_id' => $versionId])]);

    $this->asPerson($this->admin)->postJson('/api/v1/companies', ['name' => 'Beta', 'country' => 'US', 'base_currency' => 'USD', 'fiscal_year_start_month' => 1], $this->key())
        ->assertStatus(402)->assertJsonPath('code', 'plan_limit_reached')
        ->assertJsonPath('meta', ['key' => 'companies', 'limit' => 1, 'current' => 1]);
});
