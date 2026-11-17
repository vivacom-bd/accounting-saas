<?php

declare(strict_types=1);

namespace App\Services\Consolidation;

use App\Models\Consolidation\ConsolidationRun;
use App\Models\Consolidation\ConsolidationRunMember;
use App\Support\Reports\LedgerVersion;

/**
 * A run records each member's `ledger_version` when its figures are read (D16.3). If a member's books have moved since, the
 * run is stale: its figures no longer match the books, and it must be re-run (voided and computed again) before approval.
 */
final class RunStaleness
{
    public function __construct(private readonly LedgerVersion $versions) {}

    /** @return list<string> the member ids whose books moved since the run read them */
    public function movedMembers(ConsolidationRun $run): array
    {
        $moved = [];
        foreach (ConsolidationRunMember::query()->where('run_id', $run->id)->get() as $m) {
            if ($this->versions->current($run->tenant_id, $m->company_id) !== $m->ledger_version) {
                $moved[] = $m->member_id;
            }
        }

        return $moved;
    }

    public function isStale(ConsolidationRun $run): bool
    {
        return in_array($run->status, ['computed', 'approved'], true) && $this->movedMembers($run) !== [];
    }
}
