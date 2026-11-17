<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerFixture;

/* Entitlements and usage reach the app role only through sysops functions bound to app.tenant_id (plan Task 10, T7). */

function entSqlState(Closure $fn): ?string
{
    try {
        $fn();
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

it('bump_usage_refuses_without_context', function (): void {
    LedgerFixture::create();
    app(TenantContext::class)->clear();

    expect(entSqlState(fn () => DB::select("SELECT sysops.bump_usage('journal_lines', 1)")))->toBe('42501')
        ->and(entSqlState(fn () => DB::select('SELECT sysops.current_entitlements()')))->toBe('42501')
        ->and(entSqlState(fn () => DB::select('SELECT * FROM sysops.usage_summary()')))->toBe('42501');
});

it('accepts only the known metrics and positive amounts, and upserts the day row', function (): void {
    $f = LedgerFixture::create();

    expect(entSqlState(fn () => DB::select("SELECT sysops.bump_usage('bogus', 1)")))->not->toBeNull();
    DB::select("SELECT sysops.bump_usage('journal_lines', 5)");
    DB::select("SELECT sysops.bump_usage('journal_lines', 7)");
    DB::select("SELECT sysops.bump_usage('api_calls', 1)");

    $rows = DB::connection('pgsql_owner')->table('usage_daily')->where('tenant_id', $f->tenantId)->orderBy('metric')->get();
    expect($rows->pluck('value', 'metric')->map(fn ($v) => (int) $v)->all())->toBe(['api_calls' => 1, 'journal_lines' => 12]);

    $summary = collect(DB::select('SELECT * FROM sysops.usage_summary()'))->keyBy('metric');
    expect((int) $summary['journal_lines']->today)->toBe(12)->and((int) $summary['journal_lines']->month)->toBe(12)
        ->and((int) $summary['storage_bytes']->total)->toBe(0)->and($summary->keys()->sort()->values()->all())->toBe(['api_calls', 'journal_lines', 'reports', 'storage_bytes']);
});

it('bumps once per dedup key', function (): void {
    LedgerFixture::create();

    DB::select("SELECT sysops.bump_usage('journal_lines', 3, 'msg-1')");
    DB::select("SELECT sysops.bump_usage('journal_lines', 3, 'msg-1')");
    DB::select("SELECT sysops.bump_usage('journal_lines', 3, 'msg-2')");

    expect((int) DB::select('SELECT * FROM sysops.usage_summary()')[1]->today)->toBeGreaterThanOrEqual(0);
    $row = collect(DB::select('SELECT * FROM sysops.usage_summary()'))->firstWhere('metric', 'journal_lines');
    expect((int) $row->today)->toBe(6);
});

it('keeps each tenant to its own counters', function (): void {
    $a = LedgerFixture::create(slug: 'alpha');
    DB::select("SELECT sysops.bump_usage('api_calls', 4)");
    $b = LedgerFixture::create(slug: 'beta');
    DB::select("SELECT sysops.bump_usage('api_calls', 1)");

    $a2 = app(TenantContext::class)->run($a->tenantId, fn () => collect(DB::select('SELECT * FROM sysops.usage_summary()'))->firstWhere('metric', 'api_calls')->today);
    $b2 = app(TenantContext::class)->run($b->tenantId, fn () => collect(DB::select('SELECT * FROM sysops.usage_summary()'))->firstWhere('metric', 'api_calls')->today);
    expect([(int) $a2, (int) $b2])->toBe([4, 1]);
});

it('app_role_cannot_read_usage_daily', function (): void {
    LedgerFixture::create();

    foreach (['usage_daily', 'tenant_entitlement_overrides', 'usage_dedup'] as $table) {
        expect(entSqlState(fn () => DB::select("SELECT * FROM {$table}")))->toBe('42501', $table);
        expect(entSqlState(fn () => DB::connection('pgsql_report')->select("SELECT * FROM {$table}")))->toBe('42501', $table);
    }
    expect(entSqlState(fn () => DB::statement("INSERT INTO usage_daily (tenant_id, day, metric, value) VALUES (gen_random_uuid(), current_date, 'reports', 1)")))->toBe('42501');
});

it('lets the platform role manage overrides and nothing else of this feature', function (): void {
    $f = LedgerFixture::create();
    $platform = DB::connection('pgsql_platform');

    $platform->table('tenant_entitlement_overrides')->insert(['tenant_id' => $f->tenantId, 'key' => 'users', 'value' => '5', 'reason' => 'pilot', 'created_at' => now(), 'updated_at' => now()]);
    expect($platform->table('tenant_entitlement_overrides')->count())->toBe(1)
        ->and(entSqlState(fn () => $platform->select('SELECT * FROM usage_daily')))->toBe('42501')
        ->and(entSqlState(fn () => $platform->select('SELECT sysops.current_entitlements()')))->toBe('42501');
});

it('grants the sysops role exactly what the functions need', function (): void {
    $priv = fn (string $table, string $p): bool => (bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', ['ledgerly_sysops', $table, $p])->ok;

    foreach (['plans', 'plan_versions', 'tenant_entitlement_overrides', 'tenants', 'subscriptions'] as $t) {
        expect($priv($t, 'SELECT'))->toBeTrue($t)->and($priv($t, 'INSERT'))->toBeFalse($t)->and($priv($t, 'UPDATE'))->toBeFalse($t)->and($priv($t, 'DELETE'))->toBeFalse($t);
    }
    expect($priv('usage_daily', 'INSERT'))->toBeTrue()->and($priv('usage_daily', 'UPDATE'))->toBeTrue()->and($priv('usage_daily', 'DELETE'))->toBeFalse();
});

it('resolves the plan, applies overrides and falls back to the default plan', function (): void {
    $f = LedgerFixture::create();
    $platform = DB::connection('pgsql_platform');
    $planId = (string) Str::uuid7();
    $platform->table('plans')->insert(['id' => $planId, 'code' => 'starter', 'name' => 'Starter', 'created_at' => now(), 'updated_at' => now()]);
    $platform->table('plan_versions')->insert([
        ['id' => (string) Str::uuid7(), 'plan_id' => $planId, 'version' => 1, 'prices' => '{}', 'limits' => '{"users": 1}', 'entitlements' => '{"budgets": false}', 'effective_from' => '2026-01-01T00:00:00Z'],
        ['id' => (string) Str::uuid7(), 'plan_id' => $planId, 'version' => 2, 'prices' => '{}', 'limits' => '{"users": 3}', 'entitlements' => '{"budgets": false}', 'effective_from' => '2026-02-01T00:00:00Z'],
        ['id' => (string) Str::uuid7(), 'plan_id' => $planId, 'version' => 3, 'prices' => '{}', 'limits' => '{"users": 99}', 'entitlements' => '{"budgets": false}', 'effective_from' => '2999-01-01T00:00:00Z'],
    ]);

    $read = fn (): array => json_decode((string) DB::selectOne('SELECT sysops.current_entitlements() AS e')->e, true);
    expect($read())->toEqual(['plan_code' => 'starter', 'limits' => ['users' => 3], 'entitlements' => ['budgets' => false]]); // current version, not the scheduled one

    $platform->table('tenant_entitlement_overrides')->insert([
        ['tenant_id' => $f->tenantId, 'key' => 'users', 'value' => '10', 'reason' => 'x', 'expires_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ['tenant_id' => $f->tenantId, 'key' => 'budgets', 'value' => 'true', 'reason' => 'x', 'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()],
    ]);
    expect($read())->toEqual(['plan_code' => 'starter', 'limits' => ['users' => 10], 'entitlements' => ['budgets' => false]]); // the expired override is ignored
});

it('prefers the live subscription over the plan recorded on the tenant', function (): void {
    $f = LedgerFixture::create();
    $platform = DB::connection('pgsql_platform');
    $version = function (string $code, int $users) use ($platform): string {
        $planId = (string) Str::uuid7();
        $platform->table('plans')->insert(['id' => $planId, 'code' => $code, 'name' => ucfirst($code), 'created_at' => now(), 'updated_at' => now()]);
        $platform->table('plan_versions')->insert([
            'id' => $id = (string) Str::uuid7(), 'plan_id' => $planId, 'version' => 1, 'prices' => '{}', 'limits' => json_encode(['users' => $users]),
            'entitlements' => '{"budgets": false}', 'effective_from' => '2026-01-01T00:00:00Z',
        ]);

        return $id;
    };
    $settingsVersion = $version('starter', 3);
    $subscribedVersion = $version('growth', 25);
    DB::connection('pgsql_owner')->table('tenants')->where('id', $f->tenantId)->update(['settings' => json_encode(['plan_version_id' => $settingsVersion])]);
    $read = fn (): array => json_decode((string) DB::selectOne('SELECT sysops.current_entitlements() AS e')->e, true);
    expect($read()['plan_code'])->toBe('starter'); // no subscription yet: the settings fallback

    $sub = fn (string $status) => $platform->table('subscriptions')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $f->tenantId, 'plan_version_id' => $subscribedVersion, 'collection' => 'offline', 'interval' => 'month',
        'currency' => 'USD', 'quantity' => 1, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $sub('cancelled');
    expect($read()['plan_code'])->toBe('starter'); // a cancelled subscription is not live

    $sub('active');
    expect($read())->toEqual(['plan_code' => 'growth', 'limits' => ['users' => 25], 'entitlements' => ['budgets' => false]]);

    $platform->table('tenant_entitlement_overrides')->insert(['tenant_id' => $f->tenantId, 'key' => 'users', 'value' => '40', 'reason' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    expect($read()['limits'])->toEqual(['users' => 40]);
});
