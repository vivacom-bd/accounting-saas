<?php

declare(strict_types=1);

namespace App\Services\FixedAssets;

use App\Actions\FixedAssets\Inputs\DepreciationRunInput;
use App\Contracts\IdGenerator;
use App\Domain\Fiscal\PeriodStatus;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Fiscal\FiscalPeriod;
use App\Models\FixedAssets\DepreciationRun;
use App\Models\FixedAssets\DepreciationRunLine;
use App\Support\Actor;
use App\Support\UnitOfWork\LockSet;

/**
 * What drafting a depreciation run and editing a draft have in common: the rules about the period, the figures to charge, and
 * the stored lines. A draft being edited does not count against itself.
 */
final class DepreciationRunDrafter
{
    public function __construct(
        private readonly IdGenerator $ids,
        private readonly DepreciationPlanner $planner,
        private readonly DepreciationCalendarLoader $calendars,
    ) {}

    /**
     * Locks and checks the period, then works out the charge up to its end.
     *
     * @return array{0: FiscalPeriod, 1: DepreciationPlan}
     */
    public function plan(LockSet $locks, DepreciationRunInput $in, ?string $exceptRunId = null): array
    {
        $period = $locks->periodsById($in->companyId, [$in->periodId])[0] ?? throw DomainRuleViolation::single('not_found', 'The period does not exist.');
        if ($period->kind !== 'regular') {
            throw DomainRuleViolation::single('depreciation_period_invalid', 'Depreciation runs on a regular period, not the adjustment period.');
        }
        if (! in_array($period->status, [PeriodStatus::Open, PeriodStatus::SoftClosed], true)) {
            throw DomainRuleViolation::single('period_closed', "The period is {$period->status->value}; reopen it before depreciating.");
        }

        return [$period, $this->planFor($in, $period, $exceptRunId)];
    }

    private function planFor(DepreciationRunInput $in, FiscalPeriod $period, ?string $exceptRunId): DepreciationPlan
    {
        $active = DepreciationRun::query()->where('company_id', $in->companyId)->whereNotIn('status', ['void', 'reversed'])
            ->when($exceptRunId !== null, fn ($q) => $q->where('id', '!=', $exceptRunId));
        if ($active->clone()->where('fiscal_period_id', $period->id)->exists()) {
            throw DomainRuleViolation::single('depreciation_run_exists', 'This period already has a depreciation run. Void or reverse it first.');
        }
        if ($active->clone()->where('as_of', '<', $period->ends_on->format('Y-m-d'))->whereIn('status', ['draft', 'pending_approval'])->exists()) {
            throw DomainRuleViolation::single('earlier_run_pending', 'An earlier period\'s depreciation run is still waiting to be posted. Post or void it first.');
        }

        $plan = $this->planner->plan($in->companyId, $period->ends_on->format('Y-m-d'), $in->units);
        if ($plan->pendingUnits !== []) {
            $names = implode(', ', array_map(static fn ($a): string => $a->number, $plan->pendingUnits));

            throw DomainRuleViolation::single('units_required', "Enter the units used this period for: {$names}.");
        }
        if ($plan->isEmpty()) {
            throw DomainRuleViolation::single('nothing_to_depreciate', 'No asset has depreciation due up to the end of this period.');
        }

        return $plan;
    }

    /** Stores the plan's lines under $run and answers how many. */
    public function writeLines(Actor $actor, DepreciationRun $run, FiscalPeriod $period, DepreciationPlan $plan): int
    {
        $calendar = $this->calendars->forCompany($run->company_id);
        $no = 0;
        foreach ($plan->lines as $line) {
            $charge = $line['charge'];
            (new DepreciationRunLine)->forceFill([
                'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $run->company_id, 'depreciation_run_id' => $run->id,
                'line_no' => ++$no, 'asset_id' => $line['asset']->id, 'amount' => $charge->amount, 'months' => count($charge->months),
                'units' => $charge->units, 'detail' => ['schedule' => $charge->schedule, 'catch_up_months' => $charge->catchUpMonths($period->ends_on->format('Y-m-d'), $calendar)],
            ])->save();
        }

        return $no;
    }
}
