<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixture;

/*
 * Plan Task 15: the support sysops functions. admin_search_companies and admin_webhook_deliveries are platform-only
 * (the admin_* rule, also covered by AdminSysopsTest); record_mail_sent and active_announcements are the two the
 * tenant app role may call, bound to app.tenant_id.
 */

function supPlatform(): Connection
{
    app(TenantContext::class)->clear();

    return DB::connection('pgsql_platform');
}

function supState(Closure $fn): ?string
{
    try {
        $fn();
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

it('searches companies across tenants with escaped wildcards, two characters minimum and at most ten rows', function (): void {
    $f = LedgerFixture::create(slug: 'alpha');
    DB::table('companies')->where('id', $f->companyId)->update(['name' => '100%_Real Co']);
    for ($i = 1; $i <= 12; $i++) {
        $t = LedgerFixture::create(slug: "bulk-{$i}");
        DB::table('companies')->where('id', $t->companyId)->update(['name' => "Bulk {$i}"]);
    }
    $search = fn (string $q, int $lim = 10) => supPlatform()->select('SELECT * FROM sysops.admin_search_companies(?, ?)', [$q, $lim]);

    expect(array_keys((array) $search('bulk')[0]))->toBe(['id', 'tenant_id', 'tenant_name', 'name', 'country', 'status'])
        ->and($search('bulk'))->toHaveCount(10)
        ->and($search('bulk', 500))->toHaveCount(10)
        ->and($search('bulk', 3))->toHaveCount(3)
        ->and($search('%'))->toBe([])
        ->and($search('__'))->toBe([])
        ->and($search('%%'))->toBe([])
        ->and($search('b'))->toBe([])
        ->and($search('  '))->toBe([])
        ->and($search('0%_R'))->toHaveCount(1)
        ->and($search('0%_R')[0]->id)->toBe($f->companyId)
        ->and($search('0x_R'))->toBe([]);
});

it('lists webhook deliveries with the host only and no payload, secret or signature', function (): void {
    $f = LedgerFixture::create(slug: 'alpha');
    app(TenantContext::class)->set($f->tenantId);
    $endpoint = (string) Str::uuid7();
    DB::table('webhook_endpoints')->insert([
        'id' => $endpoint, 'tenant_id' => $f->tenantId, 'url' => 'https://u:p@Hooks.Example.test:8443/x?k=1', 'secret' => 'ENDPOINT-SECRET',
        'events' => '{a.b}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([1, 2, 3] as $n) {
        DB::table('webhook_deliveries')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $f->tenantId, 'endpoint_id' => $endpoint, 'message_id' => (string) Str::uuid7(), 'event_type' => 'a.b',
            'payload' => '{"s":"PAYLOAD-SECRET"}', 'attempts' => $n, 'window_started_at' => now(), 'response_snippet' => 'SNIPPET', 'created_at' => now()->addSeconds($n), 'updated_at' => now(),
        ]);
    }

    $rows = supPlatform()->select('SELECT * FROM sysops.admin_webhook_deliveries(?, ?)', [$f->tenantId, 2]);

    expect($rows)->toHaveCount(2)
        ->and(array_keys((array) $rows[0]))->toBe(['id', 'endpoint_id', 'host', 'event_type', 'status', 'attempts', 'response_code', 'error', 'last_attempt_at', 'next_retry_at', 'delivered_at', 'created_at'])
        ->and($rows[0]->host)->toBe('hooks.example.test')
        ->and($rows[0]->attempts)->toBe(3)
        ->and(json_encode($rows))->not->toContain('SECRET')->not->toContain('SNIPPET')->not->toContain('u:p')
        ->and(supPlatform()->select('SELECT * FROM sysops.admin_webhook_deliveries(?, ?)', [(string) Str::uuid7(), 5]))->toBe([]);
});

it('lets the app and platform roles record sent mail, tenant from the session, and nobody else', function (): void {
    $f = LedgerFixture::create(slug: 'alpha');
    $hash = hash('sha256', 'a@b.test');
    $record = fn (string $conn) => DB::connection($conn)->select('SELECT sysops.record_mail_sent(?, ?, ?, ?)', [$hash, 'a***@b.test', 'Hi', 'App\\Mail\\X']);

    app(TenantContext::class)->set($f->tenantId);
    $record('pgsql');
    app(TenantContext::class)->clear();
    $record('pgsql_platform');

    $rows = DB::connection('pgsql_owner')->table('mail_log')->orderBy('sent_at')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->tenant_id)->toBe($f->tenantId)
        ->and($rows[1]->tenant_id)->toBeNull()
        ->and(supState(fn () => DB::connection('pgsql_report')->select('SELECT sysops.record_mail_sent(?, ?, ?, ?)', [$hash, 'x', 'y', 'z'])))->toBe('42501')
        ->and(supState(fn () => DB::connection('pgsql')->table('mail_log')->count()))->toBe('42501')
        ->and(supState(fn () => DB::connection('pgsql_platform')->table('mail_log')->delete()))->toBe('42501')
        ->and(supState(fn () => DB::connection('pgsql')->select('SELECT sysops.record_mail_sent(?, ?, ?, ?)', ['not-a-hash', 'x', 'y', 'z'])))->toBe('22023');
});

it('serves active announcements to the app role for its own tenant only', function (): void {
    $f = LedgerFixture::create(slug: 'alpha');
    DB::connection('pgsql_owner')->table('tenants')->where('id', $f->tenantId)->update(['country' => 'KE']);
    $adminId = (string) Str::uuid7();
    DB::connection('pgsql_owner')->table('admin_users')->insert(['id' => $adminId, 'email' => 'a@example.test', 'name' => 'A', 'password' => 'x', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
    $insert = fn (string $title, array $audience) => DB::connection('pgsql_owner')->table('announcements')->insert([
        'id' => (string) Str::uuid7(), 'title' => $title, 'body' => 'b', 'kind' => 'info', 'audience' => json_encode($audience === [] ? new stdClass : $audience),
        'starts_at' => now()->subHour(), 'ends_at' => null, 'created_by' => $adminId, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $insert('all', []);
    $insert('ke', ['countries' => ['KE']]);
    $insert('ug', ['countries' => ['UG']]);

    app(TenantContext::class)->clear();
    expect(DB::connection('pgsql')->select('SELECT * FROM sysops.active_announcements()'))->toBe([]);
    app(TenantContext::class)->set($f->tenantId);
    $rows = DB::connection('pgsql')->select('SELECT * FROM sysops.active_announcements()');
    $titles = array_column($rows, 'title');
    sort($titles);
    expect($titles)->toBe(['all', 'ke'])
        ->and(array_keys((array) $rows[0]))->toBe(['id', 'title', 'body', 'kind', 'starts_at', 'ends_at'])
        ->and(supState(fn () => DB::connection('pgsql')->table('announcements')->count()))->toBe('42501')
        ->and(supState(fn () => DB::connection('pgsql_platform')->select('SELECT * FROM sysops.active_announcements()')))->toBe('42501');
});
