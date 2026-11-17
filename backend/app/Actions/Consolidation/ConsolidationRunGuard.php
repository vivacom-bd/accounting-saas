<?php

declare(strict_types=1);

namespace App\Actions\Consolidation;

use App\Domain\Shared\DomainRuleViolation;
use App\Models\Consolidation\ConsolidationRun;

/**
 * What history is fixed once periods are consolidated: membership, ownership and group entries dated within the end of
 * an approved run cannot change, because the run's figures stand on them. A change after a run is made as a group entry
 * dated the day after its end (a transaction is measured at the close of the run's last day).
 */
final class ConsolidationRunGuard
{
    /** The end date of the latest approved, not reversed run of the group, or null. */
    public function lastApprovedEnd(string $groupId): ?string
    {
        $run = ConsolidationRun::query()->where('group_id', $groupId)->where('status', 'approved')->orderByDesc('period_to')->first();

        return $run?->period_to->format('Y-m-d');
    }

    /** Throws when a run ends on or after $date (the date must be later than the last consolidated day). */
    public function assertNoRunAtOrAfter(string $groupId, string $date, string $message): void
    {
        $end = $this->lastApprovedEnd($groupId);
        if ($end !== null && $end >= $date) {
            throw DomainRuleViolation::single('period_already_consolidated', $message);
        }
    }

    /** Throws when a run ends after $date (the date may be the last consolidated day itself). */
    public function assertNoRunAfter(string $groupId, string $date, string $message): void
    {
        $end = $this->lastApprovedEnd($groupId);
        if ($end !== null && $end > $date) {
            throw DomainRuleViolation::single('period_already_consolidated', $message);
        }
    }
}
