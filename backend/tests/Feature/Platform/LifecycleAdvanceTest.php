<?php

declare(strict_types=1);

use App\Actions\Platform\ChangeTenantLifecycle;
use App\Domain\Admin\AdminRole;
use App\Domain\Platform\LifecycleState;
use App\Domain\Shared\DomainRuleViolation;
use App\Notifications\TenantLifecycleChanged;
use App\Support\Actor;
use App\Support\Admin\AdminActor;
use App\Support\Problem\StatusMap;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

/**
 * A tenant with one active owner (owner@<slug>.test) and one member, inserted as the owner role.
 *
 * @param  array<string, mixed>  $attrs
 */
function lifecycleTenant(array $attrs = []): string
{
    $id = (string) Str::uuid7();
    $slug = 't-'.substr($id, -12);
    $owner = DB::connection('pgsql_owner');
    $owner->table('tenants')->insert(['id' => $id, 'name' => 'Acme '.$slug, 'slug' => $slug] + $attrs);
    foreach (['owner', 'member'] as $role) {
        $userId = (string) Str::uuid7();
        $owner->table('users')->insert(['id' => $userId, 'name' => ucfirst($role), 'email' => "{$role}@{$slug}.test", 'password' => 'x']);
        $owner->table('tenant_users')->insert(['tenant_id' => $id, 'user_id' => $userId, 'role' => $role, 'status' => 'active']);
    }

    return $id;
}

function lifecycleRow(string $id): object
{
    /** @var object */
    return DB::connection('pgsql_platform')->table('tenants')->where('id', $id)->first();
}

/** @return list<object> */
function lifecycleEvents(string $id): array
{
    return DB::connection('pgsql_platform')->table('tenant_lifecycle_events')->where('tenant_id', $id)->orderBy('occurred_at')->get()->all();
}

function lifecycleUtc(mixed $timestamp): string
{
    return (new DateTimeImmutable((string) $timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
}

function lifecycleAdmin(): AdminActor
{
    return new AdminActor((string) Str::uuid7(), AdminRole::Support, '203.0.113.9', 'pest', 'req-life');
}

it('advance_catches_up_and_is_idempotent', function () {
    // Trial ended 50 days before "now" (2026-03-15 09:30 UTC), first seen by the scheduler today (Review Focus 4).
    $late = lifecycleTenant(['lifecycle' => 'trial', 'trial_ends_at' => '2026-01-24 09:30:00+00']);
    $running = lifecycleTenant(['lifecycle' => 'trial', 'trial_ends_at' => '2026-03-20 00:00:00+00']);
    $paying = lifecycleTenant(['lifecycle' => 'active']);

    $this->artisan('lifecycle:advance')->assertExitCode(0);

    $events = lifecycleEvents($late);
    expect(array_map(fn ($e) => [$e->from_status, $e->to_status, lifecycleUtc($e->occurred_at), $e->actor_type, $e->actor_id], $events))->toBe([
        ['trial', 'read_only', '2026-01-24 09:30', 'system', null],
        ['read_only', 'suspended', '2026-02-07 09:30', 'system', null],
        ['suspended', 'cancelled', '2026-03-09 09:30', 'system', null],
    ]);
    $row = lifecycleRow($late);
    expect($row->lifecycle)->toBe('cancelled')
        ->and(lifecycleUtc($row->lifecycle_since))->toBe('2026-03-09 09:30')
        ->and(lifecycleRow($running)->lifecycle)->toBe('trial')
        ->and(lifecycleRow($paying)->lifecycle)->toBe('active')
        ->and(lifecycleEvents($running))->toBe([])
        ->and(lifecycleEvents($paying))->toBe([])
        // The scheduler is not staff: no admin audit rows.
        ->and(DB::connection('pgsql_platform')->table('admin_audit_events')->count())->toBe(0);

    // Owners hear about every step; members do not (one owner, one member, three steps → three mails).
    $slug = $row->slug;
    Notification::assertSentOnDemandTimes(TenantLifecycleChanged::class, 3);
    Notification::assertSentOnDemand(TenantLifecycleChanged::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === "owner@{$slug}.test" && $n->to === 'cancelled');

    $this->artisan('lifecycle:advance')->assertExitCode(0);

    expect(lifecycleEvents($late))->toHaveCount(3)
        ->and(DB::connection('pgsql_platform')->table('tenant_deletions')->where('tenant_id', $late)->count())->toBe(1);
    Notification::assertSentOnDemandTimes(TenantLifecycleChanged::class, 3);
});

it('advances every page of tenants', function () {
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = lifecycleTenant(['lifecycle' => 'past_due', 'delinquent_since' => '2026-03-05 00:00:00+00', 'lifecycle_since' => '2026-03-05 00:00:00+00']);
    }

    $this->artisan('lifecycle:advance', ['--page' => 2])->assertExitCode(0);

    foreach ($ids as $id) {
        $row = lifecycleRow($id);
        expect($row->lifecycle)->toBe('read_only')->and(lifecycleUtc($row->lifecycle_since))->toBe('2026-03-13 00:00');
    }
});

it('cancelling_schedules_deletion_in_90_days', function () {
    $tenant = lifecycleTenant(['lifecycle' => 'active']);
    $admin = lifecycleAdmin();

    app(ChangeTenantLifecycle::class)->handle($admin, $tenant, LifecycleState::Cancelled, 'Customer asked to close the account');

    expect(lifecycleRow($tenant)->lifecycle)->toBe('cancelled');
    $deletion = DB::connection('pgsql_platform')->table('tenant_deletions')->where('tenant_id', $tenant)->sole();
    expect($deletion->status)->toBe('scheduled')
        ->and((string) $deletion->scheduled_for)->toBe('2026-06-13')
        ->and($deletion->deleted_at)->toBeNull();

    $event = lifecycleEvents($tenant)[0];
    expect([$event->from_status, $event->to_status, $event->actor_type, $event->actor_id, $event->reason])
        ->toBe(['active', 'cancelled', 'admin', $admin->id, 'Customer asked to close the account']);

    $audit = DB::connection('pgsql_platform')->table('admin_audit_events')->sole();
    expect($audit->action)->toBe('tenant.lifecycle_changed')
        ->and($audit->admin_id)->toBe($admin->id)
        ->and($audit->tenant_id)->toBe($tenant)
        ->and($audit->reason)->toBe('Customer asked to close the account')
        ->and(json_decode($audit->before, true))->toBe(['lifecycle' => 'active'])
        ->and(json_decode($audit->after, true)['lifecycle'])->toBe('cancelled');
});

it('legal_hold_does_not_stop_cancellation_but_marks_deletion_blocked', function () {
    $tenant = lifecycleTenant([
        'lifecycle' => 'suspended', 'legal_hold' => true, 'trial_ends_at' => '2026-01-01 00:00:00+00', 'lifecycle_since' => '2026-01-15 00:00:00+00',
    ]);

    $this->artisan('lifecycle:advance')->assertExitCode(0);

    expect(lifecycleRow($tenant)->lifecycle)->toBe('cancelled');
    $deletion = DB::connection('pgsql_platform')->table('tenant_deletions')->where('tenant_id', $tenant)->sole();
    expect($deletion->status)->toBe('blocked')->and((string) $deletion->scheduled_for)->toBe('2026-05-15');
});

it('invalid_move_is_refused', function (string $from, LifecycleState $to, string $code) {
    $tenant = lifecycleTenant(['lifecycle' => $from]);

    try {
        app(ChangeTenantLifecycle::class)->handle(lifecycleAdmin(), $tenant, $to, 'try');
        $this->fail('expected a refusal');
    } catch (DomainRuleViolation $e) {
        expect($e->errorCode)->toBe($code)->and(StatusMap::status($code))->toBe(422);
    }

    expect(lifecycleRow($tenant)->lifecycle)->toBe($from)
        ->and(lifecycleEvents($tenant))->toBe([])
        ->and(DB::connection('pgsql_platform')->table('admin_audit_events')->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'active → suspended' => ['active', LifecycleState::Suspended, 'lifecycle_transition_invalid'],
    'trial → trial' => ['trial', LifecycleState::Trial, 'lifecycle_transition_invalid'],
    'cancelled → deleted (purge is not built)' => ['cancelled', LifecycleState::Deleted, 'lifecycle_transition_invalid'],
]);

it('refuses to reactivate a tenant whose deletion was executed', function () {
    $tenant = lifecycleTenant(['lifecycle' => 'cancelled']);
    DB::connection('pgsql_owner')->table('tenant_deletions')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'scheduled_for' => '2026-03-01', 'status' => 'executed', 'deleted_at' => '2026-03-01 00:00:00+00',
    ]);

    expect(fn () => app(ChangeTenantLifecycle::class)->handle(lifecycleAdmin(), $tenant, LifecycleState::Active, 'oops'))
        ->toThrow(DomainRuleViolation::class);
    expect(lifecycleRow($tenant)->lifecycle)->toBe('cancelled');
});

it('payment_returns_suspended_tenant_to_active_and_clears_delinquency', function () {
    $tenant = lifecycleTenant(['lifecycle' => 'suspended', 'delinquent_since' => '2026-02-01 00:00:00+00', 'lifecycle_since' => '2026-02-23 00:00:00+00']);

    app(ChangeTenantLifecycle::class)->handle(Actor::system($tenant), $tenant, LifecycleState::Active, 'invoice_paid');

    $row = lifecycleRow($tenant);
    expect($row->lifecycle)->toBe('active')
        ->and($row->delinquent_since)->toBeNull()
        ->and(lifecycleUtc($row->lifecycle_since))->toBe('2026-03-15 09:30');
    $event = lifecycleEvents($tenant)[0];
    expect([$event->from_status, $event->to_status, $event->actor_type, $event->actor_id])->toBe(['suspended', 'active', 'system', null])
        ->and(DB::connection('pgsql_platform')->table('admin_audit_events')->count())->toBe(0);
});

it('sets delinquency on entering past due', function () {
    $tenant = lifecycleTenant(['lifecycle' => 'active']);

    app(ChangeTenantLifecycle::class)->handle(Actor::system($tenant), $tenant, LifecycleState::PastDue, 'payment_failed');

    $row = lifecycleRow($tenant);
    expect($row->lifecycle)->toBe('past_due')->and(lifecycleUtc($row->delinquent_since))->toBe('2026-03-15 09:30');
});

it('withdraws a scheduled deletion when a cancelled tenant comes back', function () {
    $tenant = lifecycleTenant(['lifecycle' => 'active']);
    $change = app(ChangeTenantLifecycle::class);
    $change->handle(lifecycleAdmin(), $tenant, LifecycleState::Cancelled, 'closing');

    $change->handle(Actor::system($tenant), $tenant, LifecycleState::Active, 'invoice_paid');

    expect(lifecycleRow($tenant)->lifecycle)->toBe('active')
        ->and(DB::connection('pgsql_platform')->table('tenant_deletions')->where('tenant_id', $tenant)->value('status'))->toBe('withdrawn');
});

it('refuses a system actor of another tenant', function () {
    $tenant = lifecycleTenant(['lifecycle' => 'active']);

    expect(fn () => app(ChangeTenantLifecycle::class)->handle(Actor::system(lifecycleTenant()), $tenant, LifecycleState::PastDue, 'x'))
        ->toThrow(LogicException::class);
});

it('keeps lifecycle events and deletions away from the tenant app and report roles', function () {
    foreach (['pgsql', 'pgsql_report'] as $connection) {
        foreach (['tenant_lifecycle_events', 'tenant_deletions'] as $table) {
            expect(fn () => DB::connection($connection)->table($table)->count())->toThrow(QueryException::class);
        }
    }
});
