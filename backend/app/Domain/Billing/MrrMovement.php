<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use InvalidArgumentException;

/**
 * Monthly recurring revenue movements (10_SaaS_Admin §8; G9: always per currency, never blended). Pure.
 *
 * Normalisation: a yearly amount counts as one twelfth per month, in integer minor units rounded half up; the
 * remainder is dropped (47000 / 12 = 3916.67 -> 3917), so twelve months of MRR can differ from the invoice by a few
 * minor units. A subscription's `custom_amount` replaces the plan price before this normalisation. Discounts are not
 * deducted: MRR is list (or custom) price.
 */
final class MrrMovement
{
    /** @param  string  $interval  'month' or 'year' */
    public static function monthly(int $amount, string $interval): int
    {
        return match ($interval) {
            'month' => $amount,
            'year' => intdiv($amount + 6, 12),
            default => throw new InvalidArgumentException("Unknown subscription interval {$interval}."),
        };
    }

    /**
     * @param  bool  $returning  the tenant had a subscription before: starting again is a reactivation, not a new logo
     * @return array{kind: 'new'|'reactivation'|'expansion'|'contraction'|'churn', delta: int}|null
     */
    public static function of(?int $oldMonthly, ?int $newMonthly, bool $returning = false): ?array
    {
        $old = $oldMonthly ?? 0;
        $new = $newMonthly ?? 0;

        return match (true) {
            $old === $new => null,
            $old === 0 => ['kind' => $returning ? 'reactivation' : 'new', 'delta' => $new],
            $new === 0 => ['kind' => 'churn', 'delta' => -$old],
            $new > $old => ['kind' => 'expansion', 'delta' => $new - $old],
            default => ['kind' => 'contraction', 'delta' => $new - $old],
        };
    }
}
