<?php

declare(strict_types=1);

use App\Domain\Platform\LifecycleState;
use App\Domain\Platform\TenantLifecycle;

/* Plan Task 5: allowed moves, written out independently of the class so a change to either is deliberate. */
const LIFECYCLE_MOVES = [
    'trial' => ['active', 'read_only', 'cancelled'],
    'active' => ['past_due', 'cancelled'],
    'past_due' => ['active', 'read_only', 'cancelled'],
    'read_only' => ['active', 'suspended', 'cancelled'],
    'suspended' => ['active', 'cancelled'],
    'cancelled' => ['active', 'deleted'],
    'deleted' => [],
];

dataset('lifecycle pairs', function () {
    foreach (LifecycleState::cases() as $from) {
        foreach (LifecycleState::cases() as $to) {
            yield "{$from->value} → {$to->value}" => [$from, $to, in_array($to->value, LIFECYCLE_MOVES[$from->value], true)];
        }
    }
});

it('allows exactly the planned moves', function (LifecycleState $from, LifecycleState $to, bool $allowed) {
    expect(TenantLifecycle::canMove($from, $to))->toBe($allowed);
})->with('lifecycle pairs');

it('matches the tenants.lifecycle enum', function () {
    expect(array_map(fn (LifecycleState $s) => $s->value, LifecycleState::cases()))
        ->toBe(['trial', 'active', 'past_due', 'read_only', 'suspended', 'cancelled', 'deleted']);
});

function lcAt(string $when): DateTimeImmutable
{
    return new DateTimeImmutable($when, new DateTimeZone('UTC'));
}

/**
 * @param  list<array{to: LifecycleState, at: DateTimeImmutable}>  $due
 * @return list<array{string, string}>
 */
function lcPlain(array $due): array
{
    return array_map(fn (array $d) => [$d['to']->value, $d['at']->format('Y-m-d H:i')], $due);
}

it('makes a trial that ended yesterday read-only at the trial end', function () {
    $due = TenantLifecycle::due(LifecycleState::Trial, lcAt('2026-03-14 09:00'), null, lcAt('2026-02-14 09:00'), lcAt('2026-03-15 09:30'));

    expect(lcPlain($due))->toBe([['read_only', '2026-03-14 09:00']]);
});

it('catches a trial that ended 50 days ago up to cancelled, each step at its own due instant', function () {
    $due = TenantLifecycle::due(LifecycleState::Trial, lcAt('2026-01-24 09:00'), null, lcAt('2025-12-24 09:00'), lcAt('2026-03-15 09:30'));

    expect(lcPlain($due))->toBe([
        ['read_only', '2026-01-24 09:00'],
        ['suspended', '2026-02-07 09:00'],
        ['cancelled', '2026-03-09 09:00'],
    ]);
});

it('makes a tenant 9 days past due read-only on day 8', function () {
    $due = TenantLifecycle::due(LifecycleState::PastDue, null, lcAt('2026-03-06 09:00'), lcAt('2026-03-06 09:00'), lcAt('2026-03-15 09:30'));

    expect(lcPlain($due))->toBe([['read_only', '2026-03-14 09:00']]);
});

it('continues the paid path from read-only and the trial path from suspended', function () {
    expect(lcPlain(TenantLifecycle::due(LifecycleState::ReadOnly, lcAt('2025-01-01'), lcAt('2026-01-01 00:00'), lcAt('2026-01-09 00:00'), lcAt('2026-03-15'))))
        ->toBe([['suspended', '2026-01-23 00:00'], ['cancelled', '2026-02-22 00:00']])
        ->and(lcPlain(TenantLifecycle::due(LifecycleState::Suspended, lcAt('2026-01-01 00:00'), null, lcAt('2026-01-15 00:00'), lcAt('2026-03-15'))))
        ->toBe([['cancelled', '2026-02-14 00:00']]);
});

it('gives a state entered late its full grace before the next step', function () {
    // Staff made the tenant read-only by hand on 1 March, long after its trial ended: suspension comes 14 days after that, not at
    // once, and cancellation 30 days after the suspension, so a late move never cascades straight to cancelled.
    $due = TenantLifecycle::due(LifecycleState::ReadOnly, lcAt('2026-01-01 00:00'), null, lcAt('2026-03-01 12:00'), lcAt('2026-03-20'));

    expect(lcPlain($due))->toBe([['suspended', '2026-03-15 12:00']]);
});

it('has nothing due for active, cancelled, a running trial or a state with no clock', function (LifecycleState $state, ?string $trialEnd, ?string $delinquent) {
    $due = TenantLifecycle::due($state, $trialEnd === null ? null : lcAt($trialEnd), $delinquent === null ? null : lcAt($delinquent), lcAt('2025-06-01'), lcAt('2026-03-15 09:30'));

    expect($due)->toBe([]);
})->with([
    'active' => [LifecycleState::Active, '2025-07-01', null],
    'cancelled' => [LifecycleState::Cancelled, '2025-07-01', '2025-07-01'],
    'deleted' => [LifecycleState::Deleted, '2025-07-01', null],
    'trial still running' => [LifecycleState::Trial, '2026-03-16', null],
    'trial without an end' => [LifecycleState::Trial, null, null],
    'past due without a date' => [LifecycleState::PastDue, null, null],
    'read-only without a clock' => [LifecycleState::ReadOnly, null, null],
]);
