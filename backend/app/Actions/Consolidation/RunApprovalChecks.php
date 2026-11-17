<?php

declare(strict_types=1);

namespace App\Actions\Consolidation;

use App\Domain\Consolidation\ConsolidationCalculator;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Consolidation\ConsolidationRun;
use App\Models\Consolidation\ConsolidationRunLine;
use App\Services\Consolidation\RunInputBuilder;

/**
 * What must hold when a second person approves a run, beyond the ledger-version staleness check: the figures are worked
 * out again from the books and the group's entries as they are now, and must equal the ones the approver is looking at.
 * (Mapping, rate and entry changes do not move a ledger version, so this catches them.)
 */
final class RunApprovalChecks
{
    public function __construct(private readonly RunInputBuilder $builder, private readonly ConsolidationCalculator $calculator) {}

    public function assertApprovable(ConsolidationRun $run): void
    {
        $prepared = $this->builder->prepare($run->group_id, $run->period_from->format('Y-m-d'), $run->period_to->format('Y-m-d'));
        $result = $this->calculator->run($prepared->input);

        $stored = [];
        foreach (ConsolidationRunLine::query()->where('run_id', $run->id)->whereIn('kind', ['local', 'translated', 'consolidated'])->get() as $l) {
            $stored["{$l->kind}|{$l->member_id}|{$l->group_account_id}"] = $l->amount;
        }
        $fresh = [];
        foreach (['local' => $result->local, 'translated' => $result->translated] as $kind => $byMember) {
            foreach ($byMember as $memberId => $rows) {
                foreach ($rows as $account => $amount) {
                    $fresh["{$kind}|{$memberId}|{$account}"] = $amount;
                }
            }
        }
        foreach ($result->consolidated as $account => $amount) {
            $fresh["consolidated||{$account}"] = $amount;
        }
        ksort($stored);
        ksort($fresh);
        if ($stored !== $fresh) {
            throw DomainRuleViolation::single('consolidation_run_stale', 'The group\'s mapping, rates or entries changed after this run was computed. Void the run and compute it again.');
        }
    }
}
