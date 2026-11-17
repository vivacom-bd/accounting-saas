<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerFixture;

/*
 * Plan Task 17: platform_settings, tenant_rate_limits and the Pennant features table are platform tables; the app role
 * reaches maintenance mode and its tenant's rate limit only through sysops functions, and reads (never writes) flags.
 */

function opsSqlState(Closure $fn): ?string
{
    try {
        $fn();
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

it('keeps the ops tables off the app role, except reading features', function (): void {
    app(TenantContext::class)->clear();
    $app = DB::connection('pgsql');

    expect(opsSqlState(fn () => $app->select('SELECT * FROM platform_settings')))->toBe('42501')
        ->and(opsSqlState(fn () => $app->select('SELECT * FROM tenant_rate_limits')))->toBe('42501')
        ->and(opsSqlState(fn () => $app->select('SELECT * FROM features')))->toBeNull()
        ->and(opsSqlState(fn () => $app->table('features')->insert(['name' => 'x', 'scope' => 's', 'value' => 'true'])))->toBe('42501')
        ->and(opsSqlState(fn () => $app->table('features')->update(['value' => 'false'])))->toBe('42501')
        ->and(opsSqlState(fn () => $app->table('features')->delete()))->toBe('42501');

    $platform = DB::connection('pgsql_platform');
    $platform->table('features')->insert(['name' => 'x', 'scope' => 's', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()]);
    $platform->table('platform_settings')->insert(['key' => 'k', 'value' => '{}', 'updated_at' => now()]);
    expect($platform->table('features')->count())->toBe(1)
        ->and(opsSqlState(fn () => DB::connection('pgsql_report')->select('SELECT * FROM features')))->toBe('42501');
});

it('reads maintenance through platform_maintenance(), off when unset, expired or disabled', function (): void {
    app(TenantContext::class)->clear();
    $app = DB::connection('pgsql');
    $platform = DB::connection('pgsql_platform');

    expect((array) $app->selectOne('SELECT * FROM sysops.platform_maintenance()'))->toBe(['enabled' => false, 'message' => null, 'ends_at' => null]);

    $set = fn (array $value) => $platform->table('platform_settings')->updateOrInsert(['key' => 'maintenance'], ['value' => json_encode($value), 'updated_at' => now()]);
    $set(['enabled' => true, 'message' => 'Back soon', 'ends_at' => null]);
    expect((array) $app->selectOne('SELECT * FROM sysops.platform_maintenance()'))->toMatchArray(['enabled' => true, 'message' => 'Back soon', 'ends_at' => null]);

    $set(['enabled' => true, 'message' => 'Back soon', 'ends_at' => now()->subMinute()->toIso8601String()]);
    expect($app->selectOne('SELECT * FROM sysops.platform_maintenance()')->enabled)->toBeFalse();

    $set(['enabled' => false, 'message' => 'x', 'ends_at' => null]);
    expect($app->selectOne('SELECT * FROM sysops.platform_maintenance()')->enabled)->toBeFalse();
});

it('returns only the current tenant\'s unexpired rate limit from tenant_rate_limit()', function (): void {
    $a = LedgerFixture::create(slug: 'alpha');
    $b = LedgerFixture::create(slug: 'beta');
    app(TenantContext::class)->clear();
    $admin = (string) Str::uuid7();
    DB::connection('pgsql_owner')->table('admin_users')->insert(['id' => $admin, 'email' => 'o@ledgerly.test', 'name' => 'O', 'password' => 'x', 'role' => 'owner']);
    $row = fn (string $tenant, int $limit, ?string $expires) => DB::connection('pgsql_platform')->table('tenant_rate_limits')->insert([
        'tenant_id' => $tenant, 'per_minute' => $limit, 'reason' => 'test', 'set_by' => $admin, 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $row($a->tenantId, 25, null);
    $row($b->tenantId, 99, now()->subMinute()->toDateTimeString());

    $ctx = app(TenantContext::class);
    expect(DB::connection('pgsql')->select('SELECT * FROM sysops.tenant_rate_limit()'))->toBe([]);
    $ctx->run($a->tenantId, fn () => expect((array) DB::selectOne('SELECT * FROM sysops.tenant_rate_limit()'))->toMatchArray(['per_minute' => 25, 'expires_at' => null]));
    $ctx->run($b->tenantId, fn () => expect(DB::select('SELECT * FROM sysops.tenant_rate_limit()'))->toBe([]));
    expect(opsSqlState(fn () => $ctx->run($a->tenantId, fn () => DB::connection('pgsql_platform')->select('SELECT * FROM sysops.tenant_rate_limit()'))))->toBe('42501');
});

it('counts the undispatched outbox for the platform role only', function (): void {
    $a = LedgerFixture::create(slug: 'alpha');
    $b = LedgerFixture::create(slug: 'beta');
    $insert = fn (LedgerFixture $f, string $at, array $extra = []) => app(TenantContext::class)->run($f->tenantId, fn () => DB::table('outbox_messages')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $f->tenantId, 'type' => 'test.event', 'payload' => '{}', 'occurred_at' => $at, ...$extra,
    ]));
    $insert($a, '2026-10-31 09:00:00+00');
    $insert($b, '2026-10-31 08:00:00+00');
    $insert($a, '2026-10-31 07:00:00+00', ['dispatched_at' => now()]);
    app(TenantContext::class)->clear();

    $row = DB::connection('pgsql_platform')->selectOne('SELECT * FROM sysops.admin_outbox_backlog()');
    expect($row->pending)->toBe(2)->and(strtotime($row->oldest))->toBe(strtotime('2026-10-31 08:00:00+00'))
        ->and(opsSqlState(fn () => DB::connection('pgsql')->select('SELECT * FROM sysops.admin_outbox_backlog()')))->toBe('42501')
        ->and(opsSqlState(fn () => DB::connection('pgsql_report')->select('SELECT * FROM sysops.admin_outbox_backlog()')))->toBe('42501');
});
