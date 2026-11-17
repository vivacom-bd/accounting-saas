<?php

declare(strict_types=1);

use App\Actions\Admin\Approvals\DecideApproval;
use App\Actions\Admin\Support\RequestImpersonationWrite;
use App\Actions\Admin\Support\StartImpersonation;
use App\Actions\Organisation\GrantSupportAccess;
use App\Domain\Admin\AdminRole;
use App\Http\Middleware\Admin\UseAdminSession;
use App\Http\Support\RequestActor;
use App\Models\Platform\User;
use App\Support\Admin\AdminActor;
use App\Support\Audit\DetachedAudit;
use App\Support\Tenancy\TenantContext;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\LedgerChecks;
use Tests\Support\LedgerFixture;
use Tests\Support\MakesApiCalls;

/*
 * Plan Task 14 (G4, T6; 13 §18; Review Focus 1 and 5): a tenant opens support access, staff start a time-boxed
 * impersonation, exchange a single-use ticket for a tenant session that acts as the user, read-only by default and
 * enforced by the database, never able to sign, ended by time, by staff, by the banner or by the tenant revoking.
 *
 * Sessions here are real: the tenant session lives in the `sessions` table and every impersonated request carries only
 * its cookie (impBrowser() forgets the in-memory guards and session first, as a real browser has nothing else).
 */

uses(MakesApiCalls::class);

beforeEach(function (): void {
    config(['session.driver' => 'database']);
    $this->setUpWorld('acme');
    $this->target = $this->person('Tina Target', ['Accountant']);
    $this->owner = User::query()->findOrFail($this->f->userId);
    app(TenantContext::class)->clear();
    $this->impCookie = '';
});

/** A person in their own browser: the test client's in-memory session is dropped first, as another browser has none of it. */
function impPerson(object $t, User $user): object
{
    app('session')->driver()->flush();

    return (fn () => $this->asPerson($user))->call($t);
}

/** The test client as a browser holding only $cookie as its tenant session cookie. */
function impBrowser(object $t, ?string $cookie = null): void
{
    app(TenantContext::class)->clear();
    app('auth')->forgetGuards();
    app('auth')->setDefaultDriver('web');
    app('session')->driver()->flush();
    $t->withCredentials()->withHeaders(['Referer' => 'http://localhost', 'Origin' => 'http://localhost', 'Accept' => 'application/json']);
    $t->withCookie((string) config('session.cookie'), $cookie ?? $t->impCookie);
}

function impGrant(object $t, int $hours = 24): void
{
    $t->inTenant(fn () => app(GrantSupportAccess::class)->handle($t->f->actor, $hours));
    app(TenantContext::class)->clear();
}

/** @return array{ticket: string, session_id: string, admin_id: string} a ticket from a fresh staff member */
function impStart(object $t, string $mode = 'read', ?string $approvalId = null, ?AdminActor $admin = null, ?string $userId = null, bool $override = false): array
{
    if ($admin === null) {
        [$staff] = $t->makeAdmin(AdminRole::Support);
        $admin = new AdminActor($staff->id, AdminRole::Support);
    }
    $r = app(StartImpersonation::class)->handle($admin, $t->f->tenantId, $userId ?? $t->target->id, $mode, 'Ticket #42: totals look wrong', $approvalId, $override);

    return ['ticket' => $r['ticket'], 'session_id' => $r['session_id'], 'admin_id' => $admin->id];
}

/** Exchanges the ticket in a browser with no tenant session (or $cookie) and keeps the new session cookie. */
function impLogin(object $t, string $ticket, ?string $cookie = ''): TestResponse
{
    impBrowser($t, $cookie);
    $response = $t->postJson('/api/v1/auth/impersonate', ['ticket' => $ticket]);
    $set = $response->getCookie((string) config('session.cookie'));
    if ($set !== null && $response->status() === 204) {
        $t->impCookie = (string) $set->getValue();
    }

    return $response;
}

/** Signs in as a read (default) or write impersonator of the target; returns the session id. */
function impAs(object $t, string $mode = 'read'): string
{
    impGrant($t);
    $approval = null;
    $admin = null;
    if ($mode === 'write') {
        [$approval, $admin] = impApprovedWrite($t);
    }
    $s = impStart($t, $mode, $approval, $admin);
    impLogin($t, $s['ticket'])->assertNoContent();
    impBrowser($t);

    return $s['session_id'];
}

/** @return array{string, AdminActor} an approved impersonate_write request by a support member, decided by an owner */
function impApprovedWrite(object $t, ?string $userId = null): array
{
    [$support] = $t->makeAdmin(AdminRole::Support);
    [$owner] = $t->makeAdmin(AdminRole::Owner);
    $actor = new AdminActor($support->id, AdminRole::Support);
    $approval = app(RequestImpersonationWrite::class)->handle($actor, $t->f->tenantId, $userId ?? $t->target->id, 'Fix the duplicate draft');
    app(DecideApproval::class)->handle(new AdminActor($owner->id, AdminRole::Owner), $approval->id, true, null);

    return [$approval->id, $actor];
}

function impJournals(object $t): int
{
    return (int) $t->inTenant(fn () => DB::table('journals')->count());
}

function impSession(string $id): object
{
    $row = DB::connection('pgsql_owner')->table('impersonation_sessions')->where('id', $id)->first();
    expect($row)->not->toBeNull();

    return $row;
}

/** A GET under the tenant stack that writes through a UnitOfWork, as some read routes do (Review Focus 5). */
function impProbeRoutes(): void
{
    Route::middleware(['api', 'auth:sanctum', 'tenant', 'tenant.writable', 'idempotent'])->group(function (): void {
        Route::get('api/v1/_probe/write', function (Request $request) {
            app(UnitOfWork::class)->run(RequestActor::user($request), function (LockSet $l, AuditBuffer $audit, Outbox $o): void {
                $audit->add('probe.written', null);
            });

            return response()->json(['ok' => true]);
        });
        Route::get('api/v1/_probe/detached', function (Request $request) {
            app(DetachedAudit::class)->record(RequestActor::user($request), 'probe.viewed');

            return response()->json(['ok' => true]);
        });
        Route::get('api/v1/_probe/boom', fn () => throw new RuntimeException('boom'));
        Route::get('api/v1/_probe/read-only', fn () => response()->json([
            'pgsql' => DB::connection('pgsql')->selectOne('SHOW default_transaction_read_only')->default_transaction_read_only,
        ]));
    });
}

// --- support access grants (tenant side) ---------------------------------------------------------------------------

it('opens support access as an org owner or admin, never a member, an impersonator or a token', function (): void {
    impPerson($this, $this->owner)->getJson('/api/v1/org/support-access')->assertOk()
        ->assertJsonPath('data.open', false)->assertJsonPath('data.grant', null);

    impPerson($this, $this->owner)->postJson('/api/v1/org/support-access', ['hours' => 72], $this->key())->assertCreated()
        ->assertJsonPath('data.open', true)
        ->assertJsonPath('data.grant.hours', 72)
        ->assertJsonPath('data.grant.granted_by', $this->owner->id)
        ->assertJsonPath('data.grant.expires_at', '2026-03-18T09:30:00+00:00');
    impPerson($this, $this->owner)->postJson('/api/v1/org/support-access', ['hours' => 5], $this->key())->assertStatus(422);

    $admin = $this->person('Ada Admin', [], org: 'admin');
    impPerson($this, $admin)->postJson('/api/v1/org/support-access', ['hours' => 24], $this->key())->assertCreated()
        ->assertJsonPath('data.grant.hours', 24);

    impPerson($this, $this->target)->getJson('/api/v1/org/support-access')->assertForbidden();
    impPerson($this, $this->target)->postJson('/api/v1/org/support-access', ['hours' => 24], $this->key())->assertForbidden();
    impPerson($this, $this->target)->postJson('/api/v1/org/support-access/revoke', [], $this->key())->assertForbidden();

    $token = $this->newToken(['journals:read', 'journals:write']);
    $this->asToken($token)->postJson('/api/v1/org/support-access', ['hours' => 24], $this->key())->assertForbidden();
    $this->asToken($token)->postJson('/api/v1/org/support-access/revoke', [], $this->key())->assertForbidden();
    $this->asToken($token)->getJson('/api/v1/org/support-access')->assertForbidden();

    $actions = $this->inTenant(fn () => DB::table('audit_events')->where('action', 'like', 'support_access.%')->orderBy('id')->pluck('action')->all());
    expect($actions)->toBe(['support_access.granted', 'support_access.revoked', 'support_access.granted']);
});

it('support access grant needs an org owner or admin even for an org admin being impersonated', function (): void {
    $admin = $this->person('Ada Admin', ['Accountant'], org: 'admin');
    impGrant($this);
    [$approval, $staff] = impApprovedWrite($this, $admin->id);
    $s = impStart($this, 'write', $approval, $staff, $admin->id);
    impLogin($this, $s['ticket'])->assertNoContent();

    impBrowser($this);
    $this->postJson('/api/v1/org/support-access', ['hours' => 168], $this->key())->assertForbidden();
    impBrowser($this);
    $this->postJson('/api/v1/org/support-access/revoke', [], $this->key())->assertForbidden();
});

it('revoking the grant ends active read sessions', function (): void {
    $id = impAs($this);
    $this->getJson('/api/v1/companies')->assertOk();

    impPerson($this, $this->owner)->postJson('/api/v1/org/support-access/revoke', [], $this->key())->assertOk()
        ->assertJsonPath('data.open', false);
    expect(impSession($id)->ended_at)->not->toBeNull();

    impBrowser($this);
    $this->getJson('/api/v1/companies')->assertStatus(401)->assertJsonPath('code', 'impersonation_expired');
    impBrowser($this);
    $this->getJson('/api/v1/companies')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

// --- starting (admin side) -----------------------------------------------------------------------------------------

it('no grant no impersonation', function (): void {
    $this->asAdmin(AdminRole::Support);
    $body = ['tenant_id' => $this->f->tenantId, 'user_id' => $this->target->id, 'mode' => 'read', 'reason' => 'Ticket #1'];

    $this->postJson('/api/admin/impersonations', $body)->assertStatus(403)->assertJsonPath('code', 'support_access_closed');
    expect(DB::connection('pgsql_owner')->table('impersonation_sessions')->count())->toBe(0);

    impGrant($this);
    $started = $this->postJson('/api/admin/impersonations', $body)->assertCreated();
    expect($started->json('data.ticket'))->toHaveLength(48)
        ->and($started->json('data.url'))->toBe(config('app.frontend_url').'/impersonate?ticket='.$started->json('data.ticket'))
        ->and($started->json('data.mode'))->toBe('read')
        ->and($started->json('data.ends_at'))->toBe('2026-03-15T10:30:00+00:00');
    $row = impSession($started->json('data.session_id'));
    expect($row->ticket_hash)->toBe(hash('sha256', $started->json('data.ticket')))
        ->and(DB::connection('pgsql_owner')->table('impersonation_sessions')->where('ticket_hash', $started->json('data.ticket'))->exists())->toBeFalse();

    $this->asAdmin(AdminRole::Finance);
    $this->postJson('/api/admin/impersonations', $body)->assertForbidden();
});

it('owner override works and is flagged', function (): void {
    $body = ['tenant_id' => $this->f->tenantId, 'user_id' => $this->target->id, 'mode' => 'read', 'reason' => 'Outage triage', 'owner_override' => true];

    $this->asAdmin(AdminRole::Support);
    $this->postJson('/api/admin/impersonations', $body)->assertStatus(403)->assertJsonPath('code', 'support_access_closed');

    $owner = $this->asAdmin(AdminRole::Owner);
    $this->postJson('/api/admin/impersonations', [...$body, 'reason' => ''])->assertStatus(422);
    $started = $this->postJson('/api/admin/impersonations', $body)->assertCreated()->assertJsonPath('data.owner_override', true);

    $event = DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', 'impersonation.started')->first();
    expect($event->admin_id)->toBe($owner->id)
        ->and($event->tenant_id)->toBe($this->f->tenantId)
        ->and($event->reason)->toBe('Outage triage')
        ->and(json_decode((string) $event->after, true))->toMatchArray(['owner_override' => true, 'mode' => 'read', 'user_id' => $this->target->id]);

    impLogin($this, $started->json('data.ticket'))->assertNoContent();
    impBrowser($this);
    $this->getJson('/api/v1/companies')->assertOk();
});

it('write mode needs an approved request by another admin', function (): void {
    impGrant($this);
    $support = $this->asAdmin(AdminRole::Support);
    $body = ['tenant_id' => $this->f->tenantId, 'user_id' => $this->target->id, 'mode' => 'write', 'reason' => 'Fix the draft'];

    $this->postJson('/api/admin/impersonations', $body)->assertStatus(409)->assertJsonPath('code', 'approval_required');
    $requested = $this->postJson('/api/admin/impersonations/write-requests', ['tenant_id' => $this->f->tenantId, 'user_id' => $this->target->id, 'reason' => 'Fix the draft'])
        ->assertCreated()->assertJsonPath('data.kind', 'impersonate_write')->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.subject.tenant_id', $this->f->tenantId)->assertJsonPath('data.subject.user_id', $this->target->id);
    $approvalId = $requested->json('data.id');

    $this->postJson('/api/admin/impersonations', [...$body, 'approval_id' => $approvalId])->assertStatus(409)->assertJsonPath('code', 'approval_required');
    $this->postJson("/api/admin/approvals/{$approvalId}/approve")->assertForbidden();

    [$owner] = $this->makeAdmin(AdminRole::Owner);
    app(DecideApproval::class)->handle(new AdminActor($owner->id, AdminRole::Owner), $approvalId, true, null);

    $other = $this->person('Otto Other', ['Accountant']);
    $this->postJson('/api/admin/impersonations', [...$body, 'user_id' => $other->id, 'approval_id' => $approvalId])->assertStatus(409);
    $this->postJson('/api/admin/impersonations', [...$body, 'approval_id' => $approvalId])->assertCreated()->assertJsonPath('data.mode', 'write');
    $this->postJson('/api/admin/impersonations', [...$body, 'approval_id' => $approvalId])->assertStatus(409)->assertJsonPath('code', 'approval_required');

    expect(DB::connection('pgsql_owner')->table('admin_approvals')->where('id', $approvalId)->value('status'))->toBe('executed')
        ->and(DB::connection('pgsql_owner')->table('impersonation_sessions')->where('admin_id', $support->id)->value('approval_id'))->toBe($approvalId);
});

// --- the ticket ----------------------------------------------------------------------------------------------------

it('ticket is single use and expires in 60s', function (): void {
    impGrant($this);
    $s = impStart($this);
    impLogin($this, $s['ticket'])->assertNoContent();
    impLogin($this, $s['ticket'])->assertStatus(401)->assertJsonPath('code', 'impersonation_ticket_invalid');

    $late = impStart($this);
    $this->clock->advance('+61 seconds');
    impLogin($this, $late['ticket'])->assertStatus(401)->assertJsonPath('code', 'impersonation_ticket_invalid');
    impLogin($this, str_repeat('x', 48))->assertStatus(401)->assertJsonPath('code', 'impersonation_ticket_invalid');
    impLogin($this, '')->assertStatus(422);
});

it('logs out a tenant session already in the browser, regenerates the id and never remembers the login', function (): void {
    impPerson($this, $this->owner)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $this->owner->id);
    impGrant($this);
    $s = impStart($this);

    $response = impLogin($this, $s['ticket'], $this->sessionId)->assertNoContent();
    expect($this->impCookie)->not->toBe('')->not->toBe($this->sessionId)
        ->and($response->getCookie(app('auth')->guard('web')->getRecallerName()))->toBeNull();

    impBrowser($this);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $this->target->id);
    impBrowser($this, $this->sessionId);
    $this->getJson('/api/v1/me')->assertStatus(401);
});

it('a ticket for tenant A cannot reach tenant B', function (): void {
    $b = LedgerFixture::create(slug: 'bravo');
    app(TenantContext::class)->clear();
    DB::table('tenant_users')->insert(['tenant_id' => $b->tenantId, 'user_id' => $this->target->id, 'role' => 'owner']);
    impAs($this);

    $me = $this->getJson('/api/v1/me')->assertOk();
    expect(array_column($me->json('data.tenants'), 'id'))->toBe([$this->f->tenantId]);

    impBrowser($this);
    $this->putJson('/api/v1/session/tenant', ['tenant_id' => $b->tenantId])->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    impBrowser($this);
    $this->getJson("/api/v1/companies/{$b->companyId}/journals")->assertNotFound();
    impBrowser($this);
    $this->getJson('/api/v1/practice/overview')->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    impBrowser($this);
    $this->putJson('/api/v1/auth/user/profile-information', ['name' => 'Hijacked', 'email' => 'evil@example.test'])->assertForbidden()
        ->assertJsonPath('code', 'impersonation_forbidden');
    expect(User::query()->findOrFail($this->target->id)->email)->not->toBe('evil@example.test');
    impBrowser($this);
    $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    impBrowser($this);
    $this->postJson('/api/v1/auth/login', ['email' => $this->target->email, 'password' => 'x'])->assertForbidden();
});

it('signing out of a support session ends it without touching the user\'s remembered devices', function (): void {
    DB::table('users')->where('id', $this->target->id)->update(['remember_token' => 'their-own-remember-token']);
    $id = impAs($this);

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    expect(impSession($id)->ended_at)->not->toBeNull()
        ->and(DB::table('users')->where('id', $this->target->id)->value('remember_token'))->toBe('their-own-remember-token');
    $ended = $this->inTenant(fn () => DB::table('audit_events')->where('action', 'impersonation.ended')->first());
    expect(json_decode((string) $ended->context, true))->toMatchArray(['by' => 'sign_out', 'impersonation_id' => $id]);
    impBrowser($this);
    $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('the admin cookie alone never authenticates /api/v1 even during impersonation', function (): void {
    config(['admin.session_driver' => 'database']);
    impGrant($this);
    [$staff, $secret] = $this->makeAdmin(AdminRole::Support);
    $this->withHeaders(['Accept' => 'application/json']);
    $this->postJson('/api/admin/auth/login', ['email' => $staff->email, 'password' => self::ADMIN_API_PASSWORD])->assertOk();
    $login = $this->postJson('/api/admin/auth/two-factor', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    $adminCookie = (string) $login->getCookie(UseAdminSession::COOKIE)?->getValue();
    expect($adminCookie)->not->toBe('');

    $started = $this->postJson('/api/admin/impersonations', ['tenant_id' => $this->f->tenantId, 'user_id' => $this->target->id, 'mode' => 'read', 'reason' => 'Ticket #9'])->assertCreated();
    impLogin($this, $started->json('data.ticket'))->assertNoContent();
    impBrowser($this);
    $this->getJson('/api/v1/me')->assertOk();

    // Only the admin cookie, then the admin session id under the tenant cookie name.
    impBrowser($this, '');
    app('session')->driver('admin')->flush();
    $this->withCookie(UseAdminSession::COOKIE, $adminCookie)->getJson('/api/v1/me')->assertStatus(401);
    impBrowser($this, $adminCookie);
    $this->getJson('/api/v1/companies')->assertStatus(401);
});

// --- read mode -----------------------------------------------------------------------------------------------------

it('read mode blocks writes with 423', function (): void {
    impAs($this);
    $before = impJournals($this);

    impBrowser($this);
    $this->postJson($this->api('journals'), $this->journalBody(), $this->key())->assertStatus(423)->assertJsonPath('code', 'tenant_read_only');
    impBrowser($this);
    $this->getJson($this->api('journals'))->assertOk();
    expect(impJournals($this))->toBe($before);
});

it('read impersonation cannot write through get', function (): void {
    impProbeRoutes();
    impAs($this);
    $audit = fn () => $this->inTenant(fn () => DB::table('audit_events')->where('action', 'probe.written')->count());

    impBrowser($this);
    $this->getJson('/api/v1/_probe/write')->assertStatus(423)->assertJsonPath('code', 'tenant_read_only');
    expect($audit())->toBe(0);

    // The impersonation's own audit trail is not blocked: it goes through the detached audit connection.
    impBrowser($this);
    $this->getJson('/api/v1/_probe/detached')->assertOk();
    $event = $this->inTenant(fn () => DB::table('audit_events')->where('action', 'probe.viewed')->first());
    expect($event->actor_type)->toBe('admin_impersonating')
        ->and($event->actor_id)->toBe($this->target->id)
        ->and(json_decode((string) $event->context, true))->toHaveKeys(['admin_id', 'impersonation_id']);
});

it('resets the read-only connection after an impersonated request, even one that fails', function (): void {
    impProbeRoutes();
    impAs($this);

    impBrowser($this);
    $this->getJson('/api/v1/_probe/read-only')->assertOk()->assertJsonPath('pgsql', 'on');
    expect(DB::connection('pgsql')->selectOne('SHOW default_transaction_read_only')->default_transaction_read_only)->toBe('off');
    impBrowser($this);
    $this->getJson('/api/v1/_probe/boom')->assertStatus(500);
    expect(DB::connection('pgsql')->selectOne('SHOW default_transaction_read_only')->default_transaction_read_only)->toBe('off');

    // A normal person on the same application instance (and the same pooled connection) can write.
    $before = impJournals($this);
    impPerson($this, $this->target)->postJson($this->api('journals'), $this->journalBody(), $this->key())->assertCreated();
    impPerson($this, $this->target)->getJson('/api/v1/_probe/read-only')->assertOk()->assertJsonPath('pgsql', 'off');
    expect(impJournals($this))->toBe($before + 1);
});

// --- write mode ----------------------------------------------------------------------------------------------------

it('write mode acts as the user and the tenant audit records the admin in its context', function (): void {
    $id = impAs($this, 'write');
    $admin = impSession($id)->admin_id;

    $created = $this->postJson($this->api('journals'), $this->journalBody(), $this->key())->assertCreated();
    $journal = $this->inTenant(fn () => DB::table('journals')->where('id', $created->json('data.id'))->first());
    expect($journal->created_by)->toBe($this->target->id);

    $events = $this->inTenant(fn () => DB::table('audit_events')->whereIn('action', ['impersonation.started', 'journal.created'])->orderBy('id')->get());
    expect($events->pluck('action')->all())->toBe(['impersonation.started', 'journal.created']);
    foreach ($events as $event) {
        expect($event->actor_type)->toBe('admin_impersonating')
            ->and($event->actor_id)->toBe($this->target->id)
            ->and(json_decode((string) $event->context, true))->toMatchArray(['admin_id' => $admin, 'impersonation_id' => $id]);
    }
    expect(json_decode((string) $events[0]->context, true))->toMatchArray(['mode' => 'write'])
        ->and($this->inTenant(fn () => LedgerChecks::auditChainProblems()))->toBe([]);
});

it('impersonator cannot verify a signing pin', function (): void {
    DB::table('users')->where('id', $this->target->id)->update(['signing_pin_hash' => Hash::make('135790'), 'password' => Hash::make('Known-Password-1')]);
    impAs($this, 'write');

    $this->postJson('/api/v1/signing/verify', ['pin' => '135790'], $this->key())->assertForbidden()->assertJsonPath('code', 'impersonation_cannot_sign');
    impBrowser($this);
    // Even knowing the user's password, staff cannot replace their PIN.
    $this->putJson('/api/v1/signing/pin', ['current_password' => 'Known-Password-1', 'pin' => '246802'], $this->key())->assertForbidden()->assertJsonPath('code', 'impersonation_cannot_sign');
    expect(Hash::check('135790', (string) DB::table('users')->where('id', $this->target->id)->value('signing_pin_hash')))->toBeTrue();
});

it('read impersonator cannot verify a signing pin either', function (): void {
    DB::table('users')->where('id', $this->target->id)->update(['signing_pin_hash' => Hash::make('135790')]);
    impAs($this);

    $this->postJson('/api/v1/signing/verify', ['pin' => '135790'], $this->key())->assertStatus(423);
});

// --- ending --------------------------------------------------------------------------------------------------------

it('session expires after 60 minutes', function (): void {
    impAs($this);

    $this->clock->advance('+59 minutes');
    $this->getJson('/api/v1/companies')->assertOk();
    $this->clock->advance('+2 minutes');
    impBrowser($this);
    $this->getJson('/api/v1/companies')->assertStatus(401)->assertJsonPath('code', 'impersonation_expired');
    impBrowser($this);
    $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('shows the banner data in the session payload and nothing for a normal session', function (): void {
    $id = impAs($this);
    $admin = DB::connection('pgsql_owner')->table('admin_users')->where('id', impSession($id)->admin_id)->value('name');

    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.id', $this->target->id)
        ->assertJsonPath('data.impersonation', ['admin_name' => $admin, 'mode' => 'read', 'ends_at' => '2026-03-15T10:30:00+00:00']);

    impPerson($this, $this->owner)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.impersonation', null);
});

it('ends from the banner and from the admin side', function (): void {
    $id = impAs($this);
    $this->postJson('/api/v1/auth/impersonation/end')->assertNoContent();
    expect(impSession($id)->ended_at)->not->toBeNull();
    impBrowser($this);
    $this->getJson('/api/v1/companies')->assertStatus(401);
    $ended = $this->inTenant(fn () => DB::table('audit_events')->where('action', 'impersonation.ended')->first());
    expect($ended->actor_type)->toBe('admin_impersonating')
        ->and(json_decode((string) $ended->context, true))->toMatchArray(['impersonation_id' => $id, 'by' => 'impersonator']);

    // Staff end a session; the next tenant request is refused.
    $second = impStart($this);
    impLogin($this, $second['ticket'])->assertNoContent();
    $this->asAdmin(AdminRole::Support);
    $this->postJson("/api/admin/impersonations/{$second['session_id']}/end")->assertOk()->assertJsonPath('data.status', 'ended');
    $log = $this->getJson('/api/admin/impersonations?tenant='.$this->f->tenantId)->assertOk()->json('data');
    expect(array_column($log, 'id'))->toBe([$second['session_id'], $id])
        ->and($log[0])->toHaveKeys(['admin_id', 'admin_name', 'user_id', 'mode', 'reason', 'owner_override', 'started_at', 'ends_at', 'ended_at', 'status'])
        ->and($log[0])->not->toHaveKey('ticket_hash');
    expect(DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', 'impersonation.ended')->count())->toBe(1);

    impBrowser($this);
    $this->getJson('/api/v1/companies')->assertStatus(401)->assertJsonPath('code', 'impersonation_expired');
});
