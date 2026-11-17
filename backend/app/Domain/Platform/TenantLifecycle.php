<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use DateTimeImmutable;

/**
 * The tenant lifecycle state machine and its clocks (plan Task 5, decisions G5–G7). Pure: no I/O, no "now".
 *
 * Trial path (G5): trial ends unpaid → read_only; +14 d → suspended; +30 d more → cancelled (trial end + 44 d).
 * Paid path (G6): payment failed → past_due (delinquent_since); +8 d → read_only; +22 d → suspended; +52 d → cancelled.
 */
final class TenantLifecycle
{
    private const array MOVES = [
        'trial' => [LifecycleState::Active, LifecycleState::ReadOnly, LifecycleState::Cancelled],
        'active' => [LifecycleState::PastDue, LifecycleState::Cancelled],
        'past_due' => [LifecycleState::Active, LifecycleState::ReadOnly, LifecycleState::Cancelled],
        'read_only' => [LifecycleState::Active, LifecycleState::Suspended, LifecycleState::Cancelled],
        'suspended' => [LifecycleState::Active, LifecycleState::Cancelled],
        // → active only while no deletion was executed: checked by ChangeTenantLifecycle. → deleted is never set this round (G7).
        'cancelled' => [LifecycleState::Active, LifecycleState::Deleted],
        'deleted' => [],
    ];

    /** Steps after the start of each path: [state, days from the path start]. */
    private const array TRIAL_PATH = [['read_only', 0], ['suspended', 14], ['cancelled', 44]];

    private const array PAID_PATH = [['read_only', 8], ['suspended', 22], ['cancelled', 52]];

    public static function canMove(LifecycleState $from, LifecycleState $to): bool
    {
        return in_array($to, self::MOVES[$from->value], true);
    }

    /**
     * Every automatic transition already due at `$now`, in order, each dated at its due instant.
     *
     * The path is the paid one when `$delinquentSince` is set, otherwise the trial one (a trial, or a read-only/suspended
     * tenant that came from it). Each step is due at the later of its place on the path clock and the previous step plus
     * the path's gap between them, the current state counting as entered at `$since`: a scheduler catch-up lands exactly on
     * the path clock, while a state entered late (by staff) still gets its full grace before the next step.
     *
     * @return list<array{to: LifecycleState, at: DateTimeImmutable}>
     */
    public static function due(
        LifecycleState $state,
        ?DateTimeImmutable $trialEndsAt,
        ?DateTimeImmutable $delinquentSince,
        ?DateTimeImmutable $since,
        DateTimeImmutable $now,
    ): array {
        [$path, $start, $startState] = match (true) {
            $state === LifecycleState::Trial && $trialEndsAt !== null => [self::TRIAL_PATH, $trialEndsAt, LifecycleState::Trial],
            $state === LifecycleState::PastDue && $delinquentSince !== null => [self::PAID_PATH, $delinquentSince, LifecycleState::PastDue],
            in_array($state, [LifecycleState::ReadOnly, LifecycleState::Suspended], true) && $delinquentSince !== null => [self::PAID_PATH, $delinquentSince, LifecycleState::PastDue],
            in_array($state, [LifecycleState::ReadOnly, LifecycleState::Suspended], true) && $trialEndsAt !== null => [self::TRIAL_PATH, $trialEndsAt, LifecycleState::Trial],
            default => [[], null, null],
        };
        if ($start === null || $startState === null) {
            return [];
        }

        // Skip the path's steps up to the current state, then take every step whose instant has come.
        $current = $startState->value;
        $previousOffset = 0;
        $previousAt = null;
        $started = false;
        $due = [];
        foreach ($path as [$to, $offset]) {
            if (! $started) {
                if ($current !== $state->value) {
                    [$current, $previousOffset] = [$to, $offset];

                    continue;
                }
                $started = true;
                $previousAt = $since; // null when the state's start is unknown: the path clock alone decides
            }

            $at = $start->modify("+{$offset} days");
            if ($previousAt !== null) {
                $floor = $previousAt->modify('+'.($offset - $previousOffset).' days');
                $at = $floor > $at ? $floor : $at;
            }
            if ($at > $now) {
                break;
            }

            $due[] = ['to' => LifecycleState::from($to), 'at' => $at];
            [$previousAt, $previousOffset] = [$at, $offset];
        }

        return $due;
    }
}
