<?php

declare(strict_types=1);

use App\Http\Middleware\Admin\UseAdminSession;
use App\Http\Middleware\Admin\ValidateAdminCsrfToken;
use App\Models\Admin\AdminUser;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\LedgerFixture;

/*
 * Platform staff sign-in (plan Task 3, decisions T1–T3): password, then mandatory TOTP, on its own guard, session
 * cookie and database connection. Review Focus 1: neither surface's session authenticates the other.
 */

const ADMIN_PASSWORD = 'Staff-Password-42';
const ADMIN_RECOVERY_CODE = 'abcdefghij-klmnopqrst';

/** @param  array<string, mixed>  $attributes */
function staffAdmin(array $attributes = []): AdminUser
{
    return AdminUser::query()->create([
        'id' => (string) Str::uuid7(), 'email' => 'ops@ledgerly.test', 'name' => 'Ops Owner',
        'password' => ADMIN_PASSWORD, 'role' => 'owner', ...$attributes,
    ]);
}

/**
 * An admin with confirmed TOTP and one recovery code.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{AdminUser, string}
 */
function staffEnrolled(array $attributes = []): array
{
    $secret = (new Google2FA)->generateSecretKey();
    $admin = staffAdmin([
        'two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => [hash('sha256', ADMIN_RECOVERY_CODE)], ...$attributes,
    ]);

    return [$admin, $secret];
}

function staffOtp(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

/** Password + TOTP; leaves the test client signed in. */
function staffSignIn(string $secret, string $email = 'ops@ledgerly.test'): void
{
    test()->postJson('/api/admin/auth/login', ['email' => $email, 'password' => ADMIN_PASSWORD])
        ->assertOk()->assertExactJson(['two_factor' => 'challenge']);
    test()->postJson('/api/admin/auth/two-factor', ['code' => staffOtp($secret)])->assertOk();
}

/** @return list<string> */
function adminAuditActions(): array
{
    return DB::connection('pgsql_platform')->table('admin_audit_events')->orderBy('id')->pluck('action')->all();
}

beforeEach(function (): void {
    $this->withHeaders(['Accept' => 'application/json']);
});

it('logs in with password then totp', function (): void {
    [$admin, $secret] = staffEnrolled();

    $this->getJson('/api/admin/session')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    $this->postJson('/api/admin/auth/login', ['email' => 'OPS@ledgerly.test', 'password' => ADMIN_PASSWORD])
        ->assertOk()->assertExactJson(['two_factor' => 'challenge']);

    // The password alone signs nobody in.
    expect(auth('admin')->check())->toBeFalse();
    $this->getJson('/api/admin/session')->assertStatus(401)->assertJsonPath('code', 'admin_two_factor_required');
    $this->postJson('/api/admin/auth/two-factor/enrol')->assertStatus(409)->assertJsonPath('code', 'two_factor_already_confirmed');

    $this->postJson('/api/admin/auth/two-factor', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'invalid_two_factor_code');
    $this->postJson('/api/admin/auth/two-factor', ['code' => staffOtp($secret)])->assertOk();

    $this->getJson('/api/admin/session')->assertOk()
        ->assertJsonPath('data.id', $admin->id)
        ->assertJsonPath('data.email', 'ops@ledgerly.test')
        ->assertJsonPath('data.name', 'Ops Owner')
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.can', fn (array $can) => in_array('staff.manage', $can, true) && count($can) === 21);

    expect(adminAuditActions())->toBe(['auth.two_factor_failed', 'auth.login'])
        ->and($admin->fresh()?->last_login_at)->not->toBeNull()
        ->and(auth('web')->check())->toBeFalse();
    $login = DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', 'auth.login')->first();
    expect($login->admin_id)->toBe($admin->id)->and($login->subject_type)->toBe('admin_user')->and($login->subject_id)->toBe($admin->id)
        ->and($login->ip)->toBe('127.0.0.1');
});

it('first login must enrol two factor before any route', function (): void {
    $admin = staffAdmin();

    $this->postJson('/api/admin/auth/login', ['email' => 'ops@ledgerly.test', 'password' => ADMIN_PASSWORD])
        ->assertOk()->assertExactJson(['two_factor' => 'enrol']);
    $this->getJson('/api/admin/session')->assertStatus(401)->assertJsonPath('code', 'admin_two_factor_required');
    $this->postJson('/api/admin/auth/two-factor', ['code' => '123456'])->assertStatus(401)->assertJsonPath('code', 'admin_two_factor_required');

    $enrol = $this->postJson('/api/admin/auth/two-factor/enrol')->assertOk();
    $secret = $enrol->json('secret');
    expect($enrol->json('otpauth_url'))->toStartWith('otpauth://totp/')
        ->and($enrol->json('qr_svg'))->toContain('<svg');

    $this->getJson('/api/admin/session')->assertStatus(401)->assertJsonPath('code', 'admin_two_factor_required');
    $this->postJson('/api/admin/auth/two-factor/confirm', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'invalid_two_factor_code');

    $codes = $this->postJson('/api/admin/auth/two-factor/confirm', ['code' => staffOtp($secret)])->assertOk()->json('recovery_codes');
    expect($codes)->toHaveCount(8)->and(array_unique($codes))->toHaveCount(8);

    $this->getJson('/api/admin/session')->assertOk()->assertJsonPath('data.id', $admin->id);
    // Secrets at rest are encrypted; recovery codes are not stored in the clear.
    $raw = DB::connection('pgsql_platform')->table('admin_users')->where('id', $admin->id)->first();
    expect($raw->two_factor_confirmed_at)->not->toBeNull()
        ->and($raw->two_factor_secret)->not->toContain($secret)
        ->and(decrypt($raw->two_factor_secret, false))->toBe($secret)
        ->and(decrypt($raw->two_factor_recovery_codes, false))->not->toContain($codes[0]);
    expect(adminAuditActions())->toBe(['auth.two_factor_enrol_started', 'auth.two_factor_failed', 'auth.two_factor_confirmed', 'auth.login']);
});

it('recovery code works once', function (): void {
    [$admin] = staffEnrolled();

    $this->postJson('/api/admin/auth/login', ['email' => 'ops@ledgerly.test', 'password' => ADMIN_PASSWORD])->assertOk();
    $this->postJson('/api/admin/auth/two-factor', ['recovery_code' => ADMIN_RECOVERY_CODE])->assertOk();
    $this->getJson('/api/admin/session')->assertOk();
    $this->postJson('/api/admin/auth/logout')->assertNoContent();
    $this->getJson('/api/admin/session')->assertStatus(401);

    $this->postJson('/api/admin/auth/login', ['email' => 'ops@ledgerly.test', 'password' => ADMIN_PASSWORD])->assertOk();
    $this->postJson('/api/admin/auth/two-factor', ['recovery_code' => ADMIN_RECOVERY_CODE])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_two_factor_code');
    $this->getJson('/api/admin/session')->assertStatus(401)->assertJsonPath('code', 'admin_two_factor_required');

    expect($admin->fresh()?->two_factor_recovery_codes)->toBe([])
        ->and(adminAuditActions())->toBe(['auth.recovery_code_used', 'auth.login', 'auth.logout', 'auth.two_factor_failed']);
});

it('wrong password is rate limited after five', function (): void {
    staffEnrolled();

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/admin/auth/login', ['email' => 'ops@ledgerly.test', 'password' => 'wrong-password'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
    }
    $this->postJson('/api/admin/auth/login', ['email' => 'ops@ledgerly.test', 'password' => ADMIN_PASSWORD])->assertStatus(429);
    expect(session()->has('admin.pending_id'))->toBeFalse();
});

it('does not reveal whether the email exists', function (): void {
    $admin = staffAdmin();

    $known = $this->postJson('/api/admin/auth/login', ['email' => 'ops@ledgerly.test', 'password' => 'wrong-password'])->assertStatus(422);
    $unknown = $this->postJson('/api/admin/auth/login', ['email' => 'nobody@ledgerly.test', 'password' => 'wrong-password'])->assertStatus(422);

    $strip = fn (array $body) => array_diff_key($body, ['request_id' => true]);
    expect($strip($known->json()))->toBe($strip($unknown->json()));

    $rows = DB::connection('pgsql_platform')->table('admin_audit_events')->orderBy('id')->get();
    expect($rows->pluck('action')->all())->toBe(['auth.login_failed', 'auth.login_failed'])
        ->and($rows->pluck('admin_id')->all())->toBe([null, null])
        ->and($rows->pluck('subject_id')->all())->toBe([$admin->id, null]);
    // Anonymous events belong to the same verifiable chain.
    $this->artisan('admin-audit:verify')->expectsOutputToContain('intact (2 events)')->assertExitCode(0);
});

it('blocked ip gets 403', function (): void {
    [, $secret] = staffEnrolled(['ip_allowlist' => ['10.0.0.0/8', '192.0.2.7']]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->postJson('/api/admin/auth/login', ['email' => 'ops@ledgerly.test', 'password' => ADMIN_PASSWORD])
        ->assertStatus(403)->assertJsonPath('code', 'admin_ip_blocked');
    expect(session()->has('admin.pending_id'))->toBeFalse();

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3']);
    staffSignIn($secret);
    $this->getJson('/api/admin/session')->assertOk();

    // The same signed-in session from an address outside the admin's list.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->getJson('/api/admin/session')
        ->assertStatus(403)->assertJsonPath('code', 'admin_ip_blocked');
    expect(adminAuditActions())->toBe(['auth.login_blocked', 'auth.login']);
});

it('global allowlist blocks before login', function (): void {
    config(['admin.ip_allowlist' => ['10.0.0.0/8']]);
    staffEnrolled();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5']);
    $this->getJson('/api/admin/csrf-cookie')->assertStatus(403)->assertJsonPath('code', 'admin_ip_blocked');
    $this->postJson('/api/admin/auth/login', ['email' => 'ops@ledgerly.test', 'password' => ADMIN_PASSWORD])
        ->assertStatus(403)->assertJsonPath('code', 'admin_ip_blocked');

    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
        ->postJson('/api/admin/auth/login', ['email' => 'ops@ledgerly.test', 'password' => ADMIN_PASSWORD])->assertOk();
});

it('idle session expires after 30 minutes', function (): void {
    [, $secret] = staffEnrolled();
    staffSignIn($secret);

    $this->clock->advance('+29 minutes');
    $this->getJson('/api/admin/session')->assertOk();
    $this->clock->advance('+31 minutes');
    $this->getJson('/api/admin/session')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    $this->clock->advance('-31 minutes');
    $this->getJson('/api/admin/session')->assertStatus(401);
});

it('ends a session 12 hours after sign-in however active it is', function (): void {
    [, $secret] = staffEnrolled();
    staffSignIn($secret);

    foreach (range(1, 35) as $_) {
        $this->clock->advance('+20 minutes');
        $this->getJson('/api/admin/session')->assertOk();
    }
    $this->clock->advance('+20 minutes'); // 12 h 0 min
    $this->getJson('/api/admin/session')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

/** A tenant user with the same email and password as the staff member, so only the surface separates them. */
function twinTenantUser(): LedgerFixture
{
    $f = LedgerFixture::create(slug: 'ledgerly');
    app(TenantContext::class)->clear();
    DB::table('users')->where('id', $f->userId)->update(['email' => 'ops@ledgerly.test', 'password' => Hash::make(ADMIN_PASSWORD)]);

    return $f;
}

/** The id of the stored session whose payload holds a login for the given guard. */
function storedSessionFor(string $guard): string
{
    $row = DB::table('sessions')->get()->first(fn (object $r): bool => str_contains((string) base64_decode($r->payload), "login_{$guard}_"));
    expect($row)->not->toBeNull();

    return $row->id;
}

/** The test client keeps the guard and the session store in memory between requests; a real browser has only its cookie. */
function forgetInMemorySessions(): void
{
    app('auth')->forgetGuards();
    app('session')->driver()->flush();
    app('session')->driver('admin')->flush();
    test()->withCredentials(); // JSON requests carry cookies only with credentials
}

it('tenant session cannot reach admin', function (): void {
    // A shared session table, as in production, so a replayed session id finds its payload.
    config(['session.driver' => 'database', 'admin.session_driver' => 'database']);
    staffEnrolled();
    twinTenantUser();
    $stateful = ['Referer' => 'http://localhost', 'Origin' => 'http://localhost'];

    $this->withHeaders($stateful)->postJson('/api/v1/auth/login', ['email' => 'ops@ledgerly.test', 'password' => ADMIN_PASSWORD])->assertSuccessful();
    $this->getJson('/api/v1/me')->assertOk();
    expect(auth('admin')->check())->toBeFalse();
    $this->getJson('/api/admin/session')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');

    $tenantSessionId = storedSessionFor('web');
    // Control: the tenant cookie alone signs the tenant user in.
    forgetInMemorySessions();
    $this->withHeaders($stateful)->withCookie((string) config('session.cookie'), $tenantSessionId)->getJson('/api/v1/me')->assertOk();

    // The same session id replayed under the admin cookie name.
    forgetInMemorySessions();
    $this->withCookie(UseAdminSession::COOKIE, $tenantSessionId)->getJson('/api/admin/session')
        ->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    expect(auth('admin')->check())->toBeFalse();
});

it('admin session cannot reach tenant api', function (): void {
    config(['session.driver' => 'database', 'admin.session_driver' => 'database']);
    [, $secret] = staffEnrolled();
    twinTenantUser();
    $stateful = ['Referer' => 'http://localhost', 'Origin' => 'http://localhost'];

    staffSignIn($secret);
    $this->getJson('/api/admin/session')->assertOk();
    expect(auth('web')->check())->toBeFalse();
    $this->withHeaders($stateful)->getJson('/api/v1/me')->assertStatus(401);

    $adminSessionId = storedSessionFor('admin');
    // Control: the admin cookie alone signs the staff member in.
    forgetInMemorySessions();
    $this->withCookie(UseAdminSession::COOKIE, $adminSessionId)->getJson('/api/admin/session')->assertOk();

    // The admin cookie, and the admin session id under the tenant cookie name, both reach /api/v1 unauthenticated.
    forgetInMemorySessions();
    $this->withHeaders($stateful)->getJson('/api/v1/me')->assertStatus(401);
    forgetInMemorySessions();
    $this->withHeaders($stateful)->withCookie((string) config('session.cookie'), $adminSessionId)->getJson('/api/v1/me')->assertStatus(401);
    expect(auth('web')->check())->toBeFalse();
});

it('sets its own session cookie and CSRF cookie and leaves the tenant ones alone', function (): void {
    $response = $this->getJson('/api/admin/csrf-cookie')->assertNoContent();

    $response->assertCookie(UseAdminSession::COOKIE)->assertCookie('XSRF-TOKEN-ADMIN')
        ->assertCookieMissing((string) config('session.cookie'))->assertCookieMissing('XSRF-TOKEN');
    expect(config('session.cookie'))->not->toBe(UseAdminSession::COOKIE)
        ->and(app('router')->getMiddlewareGroups()['admin'])->toContain(ValidateAdminCsrfToken::class);
});

it('inactive admin cannot log in', function (): void {
    [$admin, $secret] = staffEnrolled(['is_active' => false]);

    $this->postJson('/api/admin/auth/login', ['email' => 'ops@ledgerly.test', 'password' => ADMIN_PASSWORD])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
    expect(session()->has('admin.pending_id'))->toBeFalse();

    // Deactivated while signed in: the next request ends the session.
    $admin->forceFill(['is_active' => true])->save();
    staffSignIn($secret);
    $admin->forceFill(['is_active' => false])->save();
    app('auth')->forgetGuards(); // the next request loads the admin afresh
    $this->getJson('/api/admin/session')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');

    $failed = DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', 'auth.login_failed')->first();
    expect($failed->reason)->toBe('inactive');
});

it('admin routes use the platform connection', function (): void {
    Route::middleware('admin')->get('api/admin/_probe', fn () => ['connection' => DB::getDefaultConnection()]);

    $this->getJson('/api/admin/_probe')->assertOk()->assertExactJson(['connection' => 'pgsql_platform']);
    expect(DB::getDefaultConnection())->toBe('pgsql');
});

it('refuses an ability the role does not hold', function (): void {
    Route::middleware(['admin', 'admin.auth', 'admin.can:staff.manage'])->get('api/admin/_staff_probe', fn () => ['ok' => true]);
    [, $secret] = staffEnrolled(['role' => 'readonly']);
    staffSignIn($secret);

    $this->getJson('/api/admin/_staff_probe')->assertStatus(403)->assertJsonPath('code', 'forbidden');
    $this->getJson('/api/admin/session')->assertOk()->assertJsonPath('data.can', ['tenants.view', 'billing.view', 'plans.view', 'audit.view']);
});

it('logs out and records it', function (): void {
    [$admin, $secret] = staffEnrolled();
    staffSignIn($secret);

    $this->postJson('/api/admin/auth/logout')->assertNoContent();
    $this->getJson('/api/admin/session')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');

    $logout = DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', 'auth.logout')->first();
    expect($logout->admin_id)->toBe($admin->id);
});
