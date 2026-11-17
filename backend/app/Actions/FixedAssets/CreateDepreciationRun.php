<?php

declare(strict_types=1);

namespace App\Actions\FixedAssets;

use App\Actions\FixedAssets\Inputs\DepreciationRunInput;
use App\Actions\Ledger\SetupGuard;
use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Workflow\DocumentStatus;
use App\Models\FixedAssets\DepreciationRun;
use App\Services\FixedAssets\DepreciationRunDrafter;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Drafts the depreciation run of a period (`fixed_assets.run_depreciation`): the figures the preview showed, stored per asset.
 * Nothing posts here. Approving posts them (PostDepreciationRun), after checking that no asset changed since. A period has one
 * run unless the earlier one is voided or reversed (also a unique index), and a run cannot be drafted into a closed period.
 * UpdateDepreciationRun edits a draft with the same rules (DepreciationRunDrafter).
 */
final class CreateDepreciationRun
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly SetupGuard $guard,
        private readonly DepreciationRunDrafter $drafter,
    ) {}

    public function handle(Actor $actor, DepreciationRunInput $in): DepreciationRun
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $in) {
            $this->guard->require($actor, 'fixed_assets.run_depreciation', $in->companyId);
            [$period, $plan] = $this->drafter->plan($locks, $in);

            $run = new DepreciationRun;
            $run->forceFill([
                'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $in->companyId, 'status' => DocumentStatus::Draft,
                'fiscal_period_id' => $period->id, 'as_of' => $period->ends_on, 'total' => $plan->total, 'memo' => $in->memo,
                'created_by' => $actor->id, 'lock_version' => 0,
            ]);
            try {
                $run->save();
            } catch (UniqueConstraintViolationException) {
                throw DomainRuleViolation::single('depreciation_run_exists', 'This period already has a depreciation run. Void or reverse it first.');
            }
            $no = $this->drafter->writeLines($actor, $run, $period, $plan);
            $run->refresh()->load('lines');
            $audit->add('depreciation_run.created', $run, after: $run, context: ['assets' => $no, 'total' => $plan->total]);

            return $run;
        });
    }
}
