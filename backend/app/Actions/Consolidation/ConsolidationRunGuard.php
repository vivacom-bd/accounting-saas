<?php

declare(strict_types=1);

namespace App\Actions\Consolidation;

/**
 * What history is fixed once periods are consolidated (plan 3c.29b fills this in): membership and ownership dated on or
 * before the end of an approved run cannot change, because the run's figures stand on them. Until runs exist nothing is fixed.
 */
final class ConsolidationRunGuard
{
    public function assertNoRunAtOrAfter(string $groupId, string $date, string $message): void {}
}
