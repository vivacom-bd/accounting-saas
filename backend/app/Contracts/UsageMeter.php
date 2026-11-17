<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Counts what a tenant uses (plan Task 10). Runs inside the current tenant context. Metrics: journal_lines, api_calls,
 * storage_bytes, reports. A `$dedupKey` makes a bump idempotent: the same key counts once.
 */
interface UsageMeter
{
    public function bump(string $metric, int $n = 1, ?string $dedupKey = null): void;

    /** @return array<string, array{today: int, month: int, total: int}> keyed by metric */
    public function summary(): array;
}
