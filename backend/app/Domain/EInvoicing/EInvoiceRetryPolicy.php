<?php

declare(strict_types=1);

namespace App\Domain\EInvoicing;

/**
 * When to try a failed or unanswered submission again, and when to give up (plan 3c.27). Pure rules. After `MAX_ATTEMPTS` attempts the
 * submission is dead-lettered: no automatic retry; a person asks for one from the document. Delays are seconds, fixed (no jitter), so
 * they can be tested: 1 minute, 5 minutes, 15 minutes, 1 hour, 4 hours, 12 hours, 24 hours.
 */
final class EInvoiceRetryPolicy
{
    public const int MAX_ATTEMPTS = 8;

    /** @var list<int> */
    private const array DELAYS = [60, 300, 900, 3600, 14400, 43200, 86400];

    /** Seconds to wait after the given number of attempts have been made; null when the submission is dead-lettered. */
    public static function delayAfter(int $attempts): ?int
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        return self::DELAYS[min(max($attempts, 1), count(self::DELAYS)) - 1];
    }
}
