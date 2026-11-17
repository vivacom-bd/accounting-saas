<?php

declare(strict_types=1);

use App\Actions\Admin\Approvals\DecideApproval;
use App\Actions\Admin\Support\EndImpersonation;
use App\Actions\Admin\Support\RequestImpersonationWrite;
use App\Actions\Admin\Support\StartImpersonation;
use App\Actions\Organisation\GrantSupportAccess;
use App\Actions\Organisation\RevokeSupportAccess;
use App\Domain\Admin\AdminRole;
use App\Domain\Shared\DomainRuleViolation;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\Admin\AdminActor;
use App\Support\Impersonation\ImpersonationStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerFixture;

/*
 * Plan Task 14 (G4, T6): the Actions behind consented impersonation. Who may start one, on what basis (an open grant,
 * or an owner override with a reason), what write mode spends, what is stored (a ticket's hash, never the ticket), and
 * the single-use, 60-second ticket exchange.
 */

beforeEach(function (): void {
    $this->fx = LedgerFixture::create(slug: 'acme');
    $this->member = (string) Str::uuid7();
    DB::table('users')->insert(['id' => $this->member, 'name' => 'Mia Member', 'email' => 'mia@acme.test', 'password' => 'x']);
    DB::table('tenant_users')->insert(['tenant_id' => $this->fx->tenantId, 'user_id' => $this->member, 'role' => 'member']);
    app(TenantContext::class)->clear();
});

function impfStaff(object $t, AdminRole $role): AdminActor
{
    [$admin] = $t->makeAdmin($role);

    return new AdminActor($admin->id, $role, '10.0.0.1', 'phpunit', 'req-1');
}

function impfGrant(object $t, int $hours = 24): void
{
    app(TenantContext::class)->run($t->fx->tenantId, fn () => app(GrantSupportAccess::class)->handle($t->fx->actor, $hours));
}

function impfCode(Closure $fn): ?string
{
    try {
        $fn();
    } catch (DomainRuleViolation $e) {
        return $e->errorCode;
    }

    return null;
}

/** @return array{session_id: string, ticket: string, url: string, mode: string, ends_at: string, owner_override: bool} */
function impfStart(object $t, AdminActor $actor, string $mode = 'read', ?string $approvalId = null, bool $override = false, ?string $userId = null): array
{
    return app(StartImpersonation::class)->handle($actor, $t->fx->tenantId, $userId ?? $t->member, $mode, 'Ticket #7', $approvalId, $override);
}

it('refuses without an open grant and after the grant has expired or been revoked', function (): void {
    $support = impfStaff($this, AdminRole::Support);
    expect(impfCode(fn () => impfStart($this, $support)))->toBe('support_access_closed');

    impfGrant($this, 24);
    expect(impfStart($this, $support)['mode'])->toBe('read');

    $this->clock->advance('+25 hours');
    expect(impfCode(fn () => impfStart($this, $support)))->toBe('support_access_closed');

    impfGrant($this, 72);
    app(TenantContext::class)->run($this->fx->tenantId, fn () => app(RevokeSupportAccess::class)->handle($this->fx->actor));
    expect(impfCode(fn () => impfStart($this, $support)))->toBe('support_access_closed');
});

it('stores only the ticket hash, a 60-second ticket and a 60-minute session, and audits the start', function (): void {
    impfGrant($this);
    $support = impfStaff($this, AdminRole::Support);
    $r = impfStart($this, $support);

    $row = DB::connection('pgsql_owner')->table('impersonation_sessions')->where('id', $r['session_id'])->first();
    expect($r['ticket'])->toMatch('/^[A-Za-z0-9]{48}$/')
        ->and($row->ticket_hash)->toBe(hash('sha256', $r['ticket']))
        ->and(substr((string) $row->ticket_expires_at, 0, 19))->toBe('2026-03-15 09:31:00')
        ->and(substr((string) $row->ends_at, 0, 19))->toBe('2026-03-15 10:30:00')
        ->and($row->grant_id)->not->toBeNull()
        ->and($row->owner_override)->toBeFalse()
        ->and($row->consumed_at)->toBeNull();

    $event = DB::connection('pgsql_owner')->table('admin_audit_events')->where('action', 'impersonation.started')->first();
    expect($event->admin_id)->toBe($support->id)
        ->and($event->subject_id)->toBe($r['session_id'])
        ->and((string) $event->after)->not->toContain($r['ticket']);
});

it('owner override needs the owner role and a reason, and is used only when no grant is open', function (): void {
    expect(impfCode(fn () => impfStart($this, impfStaff($this, AdminRole::Support), override: true)))->toBe('support_access_closed');
    $owner = impfStaff($this, AdminRole::Owner);
    expect(impfCode(fn () => app(StartImpersonation::class)->handle($owner, $this->fx->tenantId, $this->member, 'read', '  ', null, true)))->toBe('reason_required');

    $r = impfStart($this, $owner, override: true);
    expect($r['owner_override'])->toBeTrue()
        ->and(DB::connection('pgsql_owner')->table('impersonation_sessions')->where('id', $r['session_id'])->value('grant_id'))->toBeNull();

    impfGrant($this);
    expect(impfStart($this, $owner, override: true)['owner_override'])->toBeFalse();
});

it('refuses staff without the ability, a non-member, an inactive member and a closed tenant', function (): void {
    impfGrant($this);
    expect(impfCode(fn () => impfStart($this, impfStaff($this, AdminRole::Finance))))->toBe('forbidden')
        ->and(impfCode(fn () => impfStart($this, impfStaff($this, AdminRole::Support), userId: (string) Str::uuid7())))->toBe('not_found');

    DB::table('tenant_users')->where('user_id', $this->member)->update(['status' => 'disabled']);
    expect(impfCode(fn () => impfStart($this, impfStaff($this, AdminRole::Support))))->toBe('not_found');

    DB::table('tenant_users')->where('user_id', $this->member)->update(['status' => 'active']);
    DB::connection('pgsql_owner')->table('tenants')->where('id', $this->fx->tenantId)->update(['lifecycle' => 'cancelled']);
    expect(impfCode(fn () => impfStart($this, impfStaff($this, AdminRole::Support))))->toBe('tenant_unavailable');
    expect(impfCode(fn () => impfStart($this, impfStaff($this, AdminRole::Support), 'bogus')))->toBe('validation_failed');
});

it('write mode spends an approval for exactly this tenant and user, requested by this staff member', function (): void {
    impfGrant($this);
    $support = impfStaff($this, AdminRole::Support);
    $owner = impfStaff($this, AdminRole::Owner);
    $approval = app(RequestImpersonationWrite::class)->handle($support, $this->fx->tenantId, $this->member, 'Fix it');
    expect($approval->subject)->toEqual(['tenant_id' => $this->fx->tenantId, 'user_id' => $this->member]);

    expect(impfCode(fn () => impfStart($this, $support, 'write', $approval->id)))->toBe('approval_required');
    app(DecideApproval::class)->handle($owner, $approval->id, true, null);
    expect(impfCode(fn () => impfStart($this, impfStaff($this, AdminRole::Support), 'write', $approval->id)))->toBe('approval_required')
        ->and(impfCode(fn () => impfStart($this, $support, 'write', $approval->id, userId: $this->fx->userId)))->toBe('approval_required');

    $r = impfStart($this, $support, 'write', $approval->id);
    expect($r['mode'])->toBe('write')
        ->and(impfCode(fn () => impfStart($this, $support, 'write', $approval->id)))->toBe('approval_required');

    expect(impfCode(fn () => app(RequestImpersonationWrite::class)->handle(impfStaff($this, AdminRole::Finance), $this->fx->tenantId, $this->member, 'x')))->toBe('forbidden');
});

it('exchanges a ticket once, within 60 seconds, for the session it opened', function (): void {
    impfGrant($this);
    $support = impfStaff($this, AdminRole::Support);
    $store = app(ImpersonationStore::class);

    $r = impfStart($this, $support);
    $first = $store->exchange($r['ticket']);
    expect($first)->not->toBeNull()
        ->and($first->id)->toBe($r['session_id'])
        ->and($first->tenantId)->toBe($this->fx->tenantId)
        ->and($first->userId)->toBe($this->member)
        ->and($first->adminId)->toBe($support->id)
        ->and($first->mode)->toBe('read')
        ->and($store->exchange($r['ticket']))->toBeNull();

    $late = impfStart($this, $support);
    $this->clock->advance('+60 seconds');
    expect($store->exchange($late['ticket']))->toBeNull();
});

it('ends a session from the admin side once, and revoking support access ends the rest', function (): void {
    impfGrant($this);
    $support = impfStaff($this, AdminRole::Support);
    $a = impfStart($this, $support);
    $b = impfStart($this, $support);

    app(EndImpersonation::class)->handle($support, $a['session_id']);
    app(EndImpersonation::class)->handle($support, $a['session_id']);
    expect(DB::connection('pgsql_owner')->table('admin_audit_events')->where('action', 'impersonation.ended')->count())->toBe(1);

    $ended = app(TenantContext::class)->run($this->fx->tenantId, fn () => app(RevokeSupportAccess::class)->handle($this->fx->actor));
    expect($ended)->toBe(1)
        ->and(DB::connection('pgsql_owner')->table('impersonation_sessions')->whereNull('ended_at')->count())->toBe(0)
        ->and(DB::connection('pgsql_owner')->table('impersonation_sessions')->where('id', $b['session_id'])->value('ended_at'))->not->toBeNull();
});

it('grants support access only for 24, 72 or 168 hours, to people who run the organisation', function (): void {
    $grant = fn (Actor $actor, int $hours) => app(TenantContext::class)->run($this->fx->tenantId, fn () => app(GrantSupportAccess::class)->handle($actor, $hours));

    expect(impfCode(fn () => $grant($this->fx->actor, 48)))->toBe('validation_failed')
        ->and(impfCode(fn () => $grant(new Actor(ActorType::User, $this->member, $this->fx->tenantId), 24)))->toBe('forbidden')
        ->and(impfCode(fn () => $grant(new Actor(ActorType::AdminImpersonating, $this->fx->userId, $this->fx->tenantId), 24)))->toBe('forbidden')
        ->and(impfCode(fn () => $grant(new Actor(ActorType::ApiClient, (string) Str::uuid7(), $this->fx->tenantId), 24)))->toBe('forbidden');

    $first = $grant($this->fx->actor, 168);
    $second = $grant($this->fx->actor, 24);
    $rows = app(TenantContext::class)->run($this->fx->tenantId, fn () => DB::table('support_access_grants')->orderBy('created_at')->orderBy('id')->get());
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('id', $first->id)->revoked_by)->toBe($this->fx->userId)
        ->and($rows->firstWhere('id', $second->id)->revoked_at)->toBeNull();
});
