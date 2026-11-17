<?php

declare(strict_types=1);

use App\Contracts\Clock;
use App\Domain\Admin\AdminRole;
use App\Features\BetaReports;
use App\Features\NewDashboard;
use App\Models\Organisation\ApiClient;
use App\Models\Platform\Tenant;
use App\Models\Platform\User;
use App\Support\Ops\MaintenanceState;
use App\Support\Ops\TenantRateLimits;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\FrozenClock;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Tests\Support\LedgerFixture;

/** Ops health, maintenance mode, rate-limit overrides and feature flags (plan Task 17). */
beforeEach(function (): void {
    $this->withHeaders(['Accept' => 'application/json']);
});

function opsAudit(string $action): int
{
    return DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', $action)->count();
}

/** Acts as a person on the SPA session guard (as the tenant API tests do). */
function opsAsPerson(TestCase $test, string $userId): TestCase
{
    app('auth')->forgetGuards();
    app('auth')->setDefaultDriver('web');
    $test->withHeaders(['Referer' => 'http://localhost', 'Origin' => 'http://localhost', 'Accept' => 'application/json']);

    return $test->actingAs(User::query()->findOrFail($userId));
}

function opsTenant(string $slug): LedgerFixture
{
    $f = LedgerFixture::create(slug: $slug);
    app(TenantContext::class)->clear();

    return $f;
}

it('maintenance_blocks_tenant_api_but_not_admin_or_login', function (): void {
    $this->asAdmin(AdminRole::Owner);

    $this->getJson('/api/admin/ops/maintenance')->assertOk()->assertJsonPath('data.enabled', false);
    $this->getJson('/api/v1/organisation')->assertStatus(401);

    $endsAt = now()->addHour()->startOfSecond();
    $this->putJson('/api/admin/ops/maintenance', ['enabled' => true, 'message' => 'Upgrading the database', 'ends_at' => $endsAt->toIso8601String()])
        ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.message', 'Upgrading the database');
    expect(opsAudit('ops.maintenance_set'))->toBe(1);

    $blocked = $this->getJson('/api/v1/organisation');
    $blocked->assertStatus(503)->assertJsonPath('code', 'maintenance')->assertJsonPath('meta.message', 'Upgrading the database');
    expect(strtotime((string) $blocked->json('meta.ends_at')))->toBe($endsAt->getTimestamp());
    expect($blocked->headers->get('Content-Type'))->toContain('application/problem+json');

    // Sign-in, the session, announcements, public invoice links and the admin surface keep working.
    expect($this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'x'])->getStatusCode())->not->toBe(503);
    expect($this->getJson('/api/v1/me')->getStatusCode())->toBe(401);
    expect($this->getJson('/api/v1/public/invoices/unknown-token')->getStatusCode())->not->toBe(503);
    $this->getJson('/api/admin/ops/health')->assertOk();
    $this->getJson('/api/admin/session')->assertOk();

    $this->putJson('/api/admin/ops/maintenance', ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
    $this->getJson('/api/v1/organisation')->assertStatus(401);
});

it('stops enforcing maintenance once its end time has passed', function (): void {
    $this->asAdmin(AdminRole::Owner);
    $this->putJson('/api/admin/ops/maintenance', ['enabled' => true, 'message' => 'Brief', 'ends_at' => now()->addHour()->toIso8601String()])->assertOk();
    DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'maintenance')
        ->update(['value' => json_encode(['enabled' => true, 'message' => 'Brief', 'ends_at' => now()->subMinute()->toIso8601String()])]);
    Cache::forget(MaintenanceState::CACHE_KEY);

    $this->getJson('/api/v1/organisation')->assertStatus(401);
});

it('lets only ops.manage change maintenance, and ops.view read it', function (): void {
    $this->asAdmin(AdminRole::Support);
    $this->getJson('/api/admin/ops/maintenance')->assertOk();
    $this->putJson('/api/admin/ops/maintenance', ['enabled' => true, 'message' => 'x'])->assertStatus(403);

    $this->asAdmin(AdminRole::Sales);
    $this->getJson('/api/admin/ops/health')->assertStatus(403);
    $this->putJson('/api/admin/ops/maintenance', ['enabled' => true])->assertStatus(403);
    expect(opsAudit('ops.maintenance_set'))->toBe(0);
});

it('validates the maintenance body', function (): void {
    $this->asAdmin(AdminRole::Owner);
    $this->putJson('/api/admin/ops/maintenance', [])->assertStatus(422);
    $this->putJson('/api/admin/ops/maintenance', ['enabled' => true, 'ends_at' => now()->subDay()->toIso8601String()])->assertStatus(422);
});

it('rate_limit_override_applies_and_expires', function (): void {
    $f = opsTenant('acme');
    $this->asAdmin(AdminRole::Owner);
    $limit = function () use ($f): Limit {
        $request = Request::create('/api/v1/x');
        $request->setUserResolver(fn () => User::query()->findOrFail($f->userId));

        /** @var Limit */
        return app(TenantContext::class)->run($f->tenantId, fn () => RateLimiter::limiter('company-api')($request));
    };
    expect($limit()->maxAttempts)->toBe(300);

    $this->putJson("/api/admin/tenants/{$f->tenantId}/rate-limit", ['per_minute' => 40, 'reason' => 'Abusive import loop', 'expires_at' => now()->addDay()->toIso8601String()])
        ->assertOk()->assertJsonPath('data.per_minute', 40)->assertJsonPath('data.reason', 'Abusive import loop');
    expect($limit()->maxAttempts)->toBe(40)
        ->and(opsAudit('ops.rate_limit_set'))->toBe(1);
    $this->getJson("/api/admin/tenants/{$f->tenantId}/rate-limit")->assertOk()->assertJsonPath('data.per_minute', 40);

    // It applies to API clients as well.
    $request = Request::create('/api/v1/x');
    $client = new ApiClient;
    $client->forceFill(['id' => (string) Str::uuid7()]);
    $request->setUserResolver(fn () => $client);
    expect(app(TenantContext::class)->run($f->tenantId, fn () => RateLimiter::limiter('company-api')($request))->maxAttempts)->toBe(40);

    // Expired: a cached copy is ignored once its expiry passes, and the database stops returning it.
    $original = app(Clock::class);
    app()->instance(Clock::class, new FrozenClock($original->now()->modify('+2 years')->format('Y-m-d H:i:s')));
    expect($limit()->maxAttempts)->toBe(300);
    app()->instance(Clock::class, $original);
    expect($limit()->maxAttempts)->toBe(40);
    DB::connection('pgsql_owner')->table('tenant_rate_limits')->where('tenant_id', $f->tenantId)->update(['expires_at' => now()->subMinute()]);
    Cache::forget(TenantRateLimits::cacheKey($f->tenantId));
    expect($limit()->maxAttempts)->toBe(300);

    // Cleared by the admin: back to the default straight away, and audited.
    $this->putJson("/api/admin/tenants/{$f->tenantId}/rate-limit", ['per_minute' => 60, 'reason' => 'again'])->assertOk();
    expect($limit()->maxAttempts)->toBe(60);
    $this->deleteJson("/api/admin/tenants/{$f->tenantId}/rate-limit")->assertNoContent();
    expect($limit()->maxAttempts)->toBe(300)
        ->and(opsAudit('ops.rate_limit_cleared'))->toBe(1);
    $this->getJson("/api/admin/tenants/{$f->tenantId}/rate-limit")->assertOk()->assertJsonPath('data', null);
});

it('validates and gates the rate-limit override', function (): void {
    $f = opsTenant('acme');
    $this->asAdmin(AdminRole::Owner);
    $this->putJson("/api/admin/tenants/{$f->tenantId}/rate-limit", ['per_minute' => 0, 'reason' => 'x'])->assertStatus(422);
    $this->putJson("/api/admin/tenants/{$f->tenantId}/rate-limit", ['per_minute' => 10])->assertStatus(422);
    $this->putJson('/api/admin/tenants/'.Str::uuid7().'/rate-limit', ['per_minute' => 10, 'reason' => 'x'])->assertStatus(404);

    $this->asAdmin(AdminRole::Support);
    $this->putJson("/api/admin/tenants/{$f->tenantId}/rate-limit", ['per_minute' => 10, 'reason' => 'x'])->assertStatus(403);
    $this->deleteJson("/api/admin/tenants/{$f->tenantId}/rate-limit")->assertStatus(403);
});

it('flag_override_for_one_tenant_only', function (): void {
    $a = opsTenant('alpha');
    $b = opsTenant('beta');
    $this->asAdmin(AdminRole::Owner);
    $tenantA = Tenant::query()->findOrFail($a->tenantId);
    $tenantB = Tenant::query()->findOrFail($b->tenantId);

    $flags = $this->getJson('/api/admin/flags')->assertOk()->json('data');
    expect(collect($flags)->pluck('name')->all())->toEqualCanonicalizing(['beta-reports', 'new-dashboard'])
        ->and(collect($flags)->firstWhere('name', 'beta-reports'))->toMatchArray(['default' => false, 'global' => null, 'overrides_count' => 0])
        ->and(collect($flags)->firstWhere('name', 'beta-reports')['description'])->toBeString();
    expect(Feature::for($tenantA)->active(BetaReports::class))->toBeFalse();

    $this->putJson("/api/admin/tenants/{$a->tenantId}/flags/beta-reports", ['active' => true])->assertOk()->assertJsonPath('data.active', true);
    Feature::flushCache();
    expect(Feature::for($tenantA)->active(BetaReports::class))->toBeTrue()
        ->and(Feature::for($tenantB)->active(BetaReports::class))->toBeFalse()
        ->and(Feature::for($tenantA)->active(NewDashboard::class))->toBeFalse()
        ->and(opsAudit('flag.tenant_set'))->toBe(1);
    expect(collect($this->getJson('/api/admin/flags')->json('data'))->firstWhere('name', 'beta-reports'))->toMatchArray(['global' => null, 'overrides_count' => 1]);

    // A global switch reaches everyone without an override; the override still wins, either way.
    $this->putJson('/api/admin/flags/new-dashboard', ['active' => true])->assertOk();
    $this->putJson("/api/admin/tenants/{$b->tenantId}/flags/new-dashboard", ['active' => false])->assertOk();
    Feature::flushCache();
    expect(Feature::for($tenantA)->active(NewDashboard::class))->toBeTrue()
        ->and(Feature::for($tenantB)->active(NewDashboard::class))->toBeFalse()
        ->and(opsAudit('flag.global_set'))->toBe(1);
    expect(collect($this->getJson('/api/admin/flags')->json('data'))->firstWhere('name', 'new-dashboard'))->toMatchArray(['global' => true, 'overrides_count' => 1]);

    // Reading a flag never writes to the table: only admin edits create rows.
    expect(DB::connection('pgsql_owner')->table('features')->count())->toBe(3);

    // null removes the override (and the global switch).
    $this->putJson("/api/admin/tenants/{$a->tenantId}/flags/beta-reports", ['active' => null])->assertOk()->assertJsonPath('data.active', null);
    $this->putJson('/api/admin/flags/new-dashboard', ['active' => null])->assertOk();
    Feature::flushCache();
    expect(Feature::for($tenantA)->active(BetaReports::class))->toBeFalse()
        ->and(Feature::for($tenantA)->active(NewDashboard::class))->toBeFalse();
});

it('rejects unknown flags and gates flag writes on flags.manage', function (): void {
    $f = opsTenant('acme');
    $this->asAdmin(AdminRole::Owner);
    $this->putJson('/api/admin/flags/no-such-flag', ['active' => true])->assertStatus(404);
    $this->putJson("/api/admin/tenants/{$f->tenantId}/flags/no-such-flag", ['active' => true])->assertStatus(404);
    $this->putJson('/api/admin/flags/beta-reports', [])->assertStatus(422);

    $this->asAdmin(AdminRole::Support);
    $this->getJson('/api/admin/flags')->assertOk();
    $this->putJson('/api/admin/flags/beta-reports', ['active' => true])->assertStatus(403);
    $this->putJson("/api/admin/tenants/{$f->tenantId}/flags/beta-reports", ['active' => true])->assertStatus(403);
    expect(DB::connection('pgsql_owner')->table('features')->count())->toBe(0);
});

it('shows the current organisation its flags in the session payload', function (): void {
    $a = opsTenant('alpha');
    $b = opsTenant('beta');
    $this->asAdmin(AdminRole::Owner);
    $this->putJson("/api/admin/tenants/{$a->tenantId}/flags/beta-reports", ['active' => true])->assertOk();
    $this->postJson('/api/admin/auth/logout');
    Feature::flushCache();

    opsAsPerson($this, $a->userId)->withSession(['current_tenant_id' => $a->tenantId])->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.features', ['beta-reports' => true, 'new-dashboard' => false])->assertJsonPath('data.impersonation', null);

    opsAsPerson($this, $b->userId)->withSession(['current_tenant_id' => $b->tenantId])->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.features', ['beta-reports' => false, 'new-dashboard' => false]);
});

it('health_reports_failed_jobs', function (): void {
    $this->asAdmin(AdminRole::Engineer);
    $health = $this->getJson('/api/admin/ops/health')->assertOk()->json('data');
    expect($health['database'])->toBe('ok')
        ->and($health['queue']['failed'])->toBe(0)
        ->and($health['outbox_backlog']['pending'])->toBe(0)
        ->and($health['outbox_backlog']['oldest_at'])->toBeNull()
        ->and($health['mail_sent_24h'])->toBe(0);

    foreach ([1, 2] as $n) {
        DB::connection('pgsql_owner')->table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'mail', 'payload' => '{}', 'exception' => "boom {$n}", 'failed_at' => now()]);
    }
    $f = opsTenant('acme');
    app(TenantContext::class)->run($f->tenantId, fn () => DB::table('outbox_messages')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $f->tenantId, 'type' => 'test.event', 'payload' => '{}', 'occurred_at' => '2026-11-01 09:00:00+00',
    ]));

    $health = $this->getJson('/api/admin/ops/health')->assertOk()->json('data');
    expect($health['queue']['failed'])->toBe(2)
        ->and($health['queue']['pending'])->toBeInt()
        ->and($health['outbox_backlog']['pending'])->toBe(1)
        ->and(strtotime((string) $health['outbox_backlog']['oldest_at']))->toBe(strtotime('2026-11-01 09:00:00+00'));
});

it('counts mail sent in the last 24 hours in the health payload', function (): void {
    $this->asAdmin(AdminRole::Engineer);
    $row = fn (string $at) => ['id' => (string) Str::uuid7(), 'to_hash' => str_repeat('a', 64), 'to_masked' => 'a***@x.test', 'subject' => 's', 'status' => 'sent', 'sent_at' => $at];
    DB::connection('pgsql_owner')->table('mail_log')->insert([$row(app(Clock::class)->now()->modify('-2 hours')->format('Y-m-d H:i:sP')), $row(app(Clock::class)->now()->modify('-23 hours')->format('Y-m-d H:i:sP')), $row(app(Clock::class)->now()->modify('-30 hours')->format('Y-m-d H:i:sP'))]);

    expect($this->getJson('/api/admin/ops/health')->assertOk()->json('data.mail_sent_24h'))->toBe(2);
});

it('reports the scheduler heartbeat and the last audit seal run', function (): void {
    $this->asAdmin(AdminRole::Owner);
    $health = $this->getJson('/api/admin/ops/health')->assertOk()->json('data');
    expect($health['scheduler_last_beat'])->toMatchArray(['status' => 'down', 'at' => null])
        ->and($health['audit_seal_last_run'])->toMatchArray(['status' => 'unknown', 'at' => null]);

    $clock = app(Clock::class);
    Cache::put('scheduler:heartbeat', $clock->now()->format(DATE_ATOM), 600);
    $this->artisan('audit:seal')->assertSuccessful();
    $health = $this->getJson('/api/admin/ops/health')->assertOk()->json('data');
    expect($health['scheduler_last_beat']['status'])->toBe('ok')
        ->and($health['scheduler_last_beat']['at'])->toBeString()
        ->and($health['audit_seal_last_run']['at'])->toBeString();

    Cache::put('scheduler:heartbeat', $clock->now()->modify('-10 minutes')->format(DATE_ATOM), 600);
    expect($this->getJson('/api/admin/ops/health')->json('data.scheduler_last_beat.status'))->toBe('stale');
});

it('registers the scheduler heartbeat every minute', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => $e->description === 'scheduler-heartbeat');
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
});

it('answers down for a failing probe instead of a 500', function (): void {
    $this->asAdmin(AdminRole::Owner);
    config(['database.redis.default.port' => 1, 'database.redis.default.host' => '127.0.0.1']);
    Redis::purge();

    $health = $this->getJson('/api/admin/ops/health')->assertOk()->json('data');
    expect($health['redis'])->toBe('down')->and($health['database'])->toBe('ok');
});
