<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerFixture;

/*
 * Plan Task 7, 13 §6: the platform admin reaches inside tenants only through the sysops.admin_* SECURITY DEFINER
 * functions. Each one is platform-only, has a fixed search_path, returns only its columns and never a secret.
 */

const ADMIN_SYSOPS = [
    'admin_reset_two_factor' => 'uuid, uuid',
    'admin_set_member_status' => 'uuid, uuid, text',
    'admin_tenant_companies' => 'uuid',
    'admin_tenant_counts' => 'uuid[]',
    'admin_tenant_users' => 'uuid',
];

function platformDb(): Connection
{
    app(TenantContext::class)->clear();

    return DB::connection('pgsql_platform');
}

function sysopsSqlState(Closure $fn): ?string
{
    try {
        $fn();
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

/** @param list<string> $ids */
function uuidArray(array $ids): string
{
    return '{'.implode(',', $ids).'}';
}

function secondMember(object $f, string $role = 'member', string $status = 'active', bool $twoFactor = false): string
{
    $id = (string) Str::uuid7();
    DB::table('users')->insert([
        'id' => $id, 'name' => "User {$role}", 'email' => "{$role}-".substr($id, -6).'@example.test', 'password' => 'x',
        'two_factor_secret' => $twoFactor ? 'secret-value' : null, 'two_factor_recovery_codes' => $twoFactor ? 'codes-value' : null,
        'two_factor_confirmed_at' => $twoFactor ? now() : null, 'signing_pin_hash' => 'pin-hash',
    ]);
    DB::table('tenant_users')->insert(['tenant_id' => $f->tenantId, 'user_id' => $id, 'role' => $role, 'status' => $status, 'joined_at' => now()]);

    return $id;
}

it('defines each admin function as platform-only SECURITY DEFINER with a fixed search_path', function (): void {
    $functions = DB::select(<<<'SQL'
        SELECT p.proname AS name, oidvectortypes(p.proargtypes) AS args, p.prosecdef AS definer,
               pg_get_userbyid(p.proowner) AS owner, array_to_string(p.proconfig, ',') AS config, p.provolatile AS volatility,
               has_function_privilege('ledgerly_platform', p.oid, 'EXECUTE') AS platform_can,
               has_function_privilege('ledgerly_app', p.oid, 'EXECUTE') AS app_can,
               has_function_privilege('ledgerly_report', p.oid, 'EXECUTE') AS report_can,
               coalesce(array_to_string(p.proacl, ','), '') AS acl
        FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname = 'sysops' AND p.proname LIKE 'admin\_%' ORDER BY p.proname
    SQL);

    expect(array_combine(array_column($functions, 'name'), array_column($functions, 'args')))->toBe(ADMIN_SYSOPS);
    foreach ($functions as $fn) {
        expect($fn->definer)->toBeTrue()
            ->and($fn->owner)->toBe('ledgerly_sysops')
            ->and($fn->config)->toBe('search_path=pg_catalog, pg_temp')
            ->and($fn->platform_can)->toBeTrue()
            ->and($fn->app_can)->toBeFalse()
            ->and($fn->report_can)->toBeFalse()
            // no PUBLIC entry ("=X/…") in the ACL
            ->and(preg_match('/(^|,)=/', $fn->acl))->toBe(0);
    }
    $readers = array_filter($functions, fn ($f) => in_array($f->name, ['admin_tenant_counts', 'admin_tenant_companies', 'admin_tenant_users'], true));
    expect(array_unique(array_column($readers, 'volatility')))->toBe(['s']);
});

it('refuses the app and report roles', function (): void {
    $f = LedgerFixture::create(slug: 'alpha');
    app(TenantContext::class)->clear();

    expect(sysopsSqlState(fn () => DB::connection('pgsql')->select('SELECT * FROM sysops.admin_tenant_users(?)', [$f->tenantId])))->toBe('42501')
        ->and(sysopsSqlState(fn () => DB::connection('pgsql')->select('SELECT sysops.admin_reset_two_factor(?, ?)', [$f->tenantId, $f->userId])))->toBe('42501')
        ->and(sysopsSqlState(fn () => DB::connection('pgsql_report')->select('SELECT * FROM sysops.admin_tenant_counts(?::uuid[])', [uuidArray([$f->tenantId])])))->toBe('42501');
});

it('counts companies, active members and the last activity per tenant', function (): void {
    $a = LedgerFixture::create(slug: 'alpha');
    cashSale($a);
    secondMember($a);
    secondMember($a, status: 'disabled');
    // A bare tenant: one member, no company, no audit event yet.
    $b = (object) ['tenantId' => (string) Str::uuid7()];
    DB::table('tenants')->insert(['id' => $b->tenantId, 'name' => 'Beta', 'slug' => 'beta', 'lifecycle' => 'trial']);
    secondMember($b, role: 'owner');
    app(TenantContext::class)->set($a->tenantId);
    $lastA = DB::table('audit_events')->max('occurred_at');

    $rows = platformDb()->select('SELECT * FROM sysops.admin_tenant_counts(?::uuid[])', [uuidArray([$a->tenantId, $b->tenantId, (string) Str::uuid7()])]);

    expect($rows)->toHaveCount(2);
    $byId = collect($rows)->keyBy('tenant_id');
    expect(array_keys((array) $rows[0]))->toBe(['tenant_id', 'companies', 'users', 'last_activity_at'])
        ->and($byId[$a->tenantId]->companies)->toBe(1)
        ->and($byId[$a->tenantId]->users)->toBe(2)
        ->and(strtotime((string) $byId[$a->tenantId]->last_activity_at))->toBe(strtotime((string) $lastA))
        ->and($byId[$b->tenantId]->users)->toBe(1)
        ->and($byId[$b->tenantId]->companies)->toBe(0)
        ->and($byId[$b->tenantId]->last_activity_at)->toBeNull()
        ->and(platformDb()->select('SELECT * FROM sysops.admin_tenant_counts(?::uuid[])', [uuidArray([(string) Str::uuid7()])]))->toBe([]);
});

it('bounds the id list of the counts function', function (): void {
    $ids = array_map(fn () => (string) Str::uuid7(), range(1, 501));

    expect(sysopsSqlState(fn () => platformDb()->select('SELECT * FROM sysops.admin_tenant_counts(?::uuid[])', [uuidArray($ids)])))->toBe('22023')
        ->and(sysopsSqlState(fn () => platformDb()->select('SELECT * FROM sysops.admin_tenant_counts(NULL)')))->toBe('22023')
        ->and(platformDb()->select('SELECT * FROM sysops.admin_tenant_counts(?::uuid[])', [uuidArray(array_slice($ids, 0, 500))]))->toBe([]);
});

it('lists a tenant\'s companies with period and journal counts only', function (): void {
    $f = LedgerFixture::create(slug: 'alpha');
    cashSale($f);
    $f->setPeriodStatus('2026-01-15', 'closed');
    LedgerFixture::create(slug: 'beta');

    $rows = platformDb()->select('SELECT * FROM sysops.admin_tenant_companies(?)', [$f->tenantId]);

    expect($rows)->toHaveCount(1)
        ->and(array_keys((array) $rows[0]))->toBe(['id', 'name', 'status', 'base_currency', 'country', 'fiscal_year_start', 'open_periods', 'closed_periods', 'posted_journals'])
        ->and($rows[0]->id)->toBe($f->companyId)
        ->and($rows[0]->base_currency)->toBe('USD')
        ->and($rows[0]->fiscal_year_start)->toBe('2026-01-01')
        ->and($rows[0]->open_periods)->toBe(11)
        ->and($rows[0]->closed_periods)->toBe(1)
        ->and($rows[0]->posted_journals)->toBe(1)
        ->and(platformDb()->select('SELECT * FROM sysops.admin_tenant_companies(?)', [(string) Str::uuid7()]))->toBe([]);
});

it('lists a tenant\'s members without any secret', function (): void {
    $f = LedgerFixture::create(slug: 'alpha');
    $other = secondMember($f, twoFactor: true);
    LedgerFixture::create(slug: 'beta');

    $rows = platformDb()->select('SELECT * FROM sysops.admin_tenant_users(?)', [$f->tenantId]);

    expect($rows)->toHaveCount(2)
        ->and(array_keys((array) $rows[0]))->toBe(['user_id', 'name', 'email', 'org_role', 'status', 'two_factor', 'last_login_at']);
    $byId = collect($rows)->keyBy('user_id');
    expect($byId[$other]->two_factor)->toBeTrue()
        ->and($byId[$other]->org_role)->toBe('member')
        ->and($byId[$f->userId]->two_factor)->toBeFalse()
        ->and($byId[$f->userId]->org_role)->toBe('owner')
        ->and(json_encode($rows))->not->toContain('secret-value')->not->toContain('codes-value')->not->toContain('pin-hash')
        ->and(platformDb()->select('SELECT * FROM sysops.admin_tenant_users(?)', [(string) Str::uuid7()]))->toBe([]);
});

it('resets two-factor only for a member of the tenant', function (): void {
    $a = LedgerFixture::create(slug: 'alpha');
    $user = secondMember($a, twoFactor: true);
    $b = LedgerFixture::create(slug: 'beta');

    // A user of another tenant is left alone.
    expect(platformDb()->selectOne('SELECT sysops.admin_reset_two_factor(?, ?) AS done', [$b->tenantId, $user])->done)->toBeFalse();
    $row = DB::connection('pgsql_owner')->table('users')->where('id', $user)->first();
    expect($row->two_factor_secret)->toBe('secret-value');

    expect(platformDb()->selectOne('SELECT sysops.admin_reset_two_factor(?, ?) AS done', [$a->tenantId, $user])->done)->toBeTrue();
    $row = DB::connection('pgsql_owner')->table('users')->where('id', $user)->first();
    expect($row->two_factor_secret)->toBeNull()
        ->and($row->two_factor_recovery_codes)->toBeNull()
        ->and($row->two_factor_confirmed_at)->toBeNull()
        ->and($row->signing_pin_hash)->toBe('pin-hash')
        ->and($row->password)->toBe('x');

    // Nothing left to reset.
    expect(platformDb()->selectOne('SELECT sysops.admin_reset_two_factor(?, ?) AS done', [$a->tenantId, $user])->done)->toBeFalse();
});

it('sets a member active or disabled, never the last active owner', function (): void {
    $f = LedgerFixture::create(slug: 'alpha');
    $member = secondMember($f);
    $other = LedgerFixture::create(slug: 'beta');
    $status = fn (string $user) => DB::connection('pgsql_owner')->table('tenant_users')->where('tenant_id', $f->tenantId)->where('user_id', $user)->value('status');
    $set = fn (string $tenant, string $user, string $to) => platformDb()->selectOne('SELECT sysops.admin_set_member_status(?, ?, ?) AS outcome', [$tenant, $user, $to])->outcome;

    expect($set($f->tenantId, $member, 'disabled'))->toBe('updated')
        ->and($status($member))->toBe('disabled')
        ->and($set($f->tenantId, $member, 'disabled'))->toBe('unchanged')
        ->and($set($f->tenantId, $member, 'active'))->toBe('updated')
        ->and($status($member))->toBe('active')
        ->and($set($f->tenantId, $f->userId, 'disabled'))->toBe('last_owner')
        ->and($status($f->userId))->toBe('active')
        ->and($set($other->tenantId, $member, 'disabled'))->toBe('not_member')
        ->and(sysopsSqlState(fn () => $set($f->tenantId, $member, 'invited')))->toBe('22023');

    $invited = secondMember($f, status: 'invited');
    expect($set($f->tenantId, $invited, 'active'))->toBe('pending_invitation');

    $coOwner = secondMember($f, role: 'owner');
    expect($set($f->tenantId, $f->userId, 'disabled'))->toBe('updated')
        ->and($set($f->tenantId, $coOwner, 'disabled'))->toBe('last_owner');
});

it('keeps tenant notes platform-only and append-only apart from the pin', function (): void {
    $f = LedgerFixture::create(slug: 'alpha');
    $adminId = (string) Str::uuid7();
    DB::connection('pgsql_owner')->table('admin_users')->insert([
        'id' => $adminId, 'email' => 'ops@example.test', 'name' => 'Ops', 'password' => 'x', 'role' => 'support', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $note = ['id' => (string) Str::uuid7(), 'tenant_id' => $f->tenantId, 'admin_id' => $adminId, 'body' => 'hello', 'created_at' => now()];
    $platform = platformDb();

    $platform->table('tenant_notes')->insert($note);
    expect($platform->table('tenant_notes')->where('id', $note['id'])->update(['pinned' => true]))->toBe(1)
        ->and(sysopsSqlState(fn () => $platform->table('tenant_notes')->where('id', $note['id'])->update(['body' => 'rewritten'])))->toBe('42501')
        ->and(sysopsSqlState(fn () => $platform->table('tenant_notes')->where('id', $note['id'])->delete()))->toBe('42501')
        ->and(sysopsSqlState(fn () => DB::connection('pgsql')->table('tenant_notes')->count()))->toBe('42501')
        ->and(sysopsSqlState(fn () => DB::connection('pgsql_report')->table('tenant_notes')->count()))->toBe('42501');
});
