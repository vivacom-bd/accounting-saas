<?php

declare(strict_types=1);

namespace App\Actions\FixedAssets;

use App\Actions\FixedAssets\Inputs\DepreciationRunInput;
use App\Actions\Ledger\SetupGuard;
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
 * Edits a draft depreciation run in place (`fixed_assets.run_depreciation`): another period, other units, another note. The
 * figures are worked out again exactly as when drafting, and the lines replaced. Once submitted it is not editable (return
 * it first); once posted it is immutable. Optimistic lock on `version`; one audit event with the before and after.
 */
final class UpdateDepreciationRun
{
    public function __construct(private readonly UnitOfWork $uow, private readonly SetupGuard $guard, private readonly DepreciationRunDrafter $drafter) {}

    public function handle(Actor $actor, string $runId, DepreciationRunInput $in, int $version): DepreciationRun
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $runId, $in, $version) {
            $this->guard->require($actor, 'fixed_assets.run_depreciation', $in->companyId);
            // Level 1 (the period) before level 2 (the document), as everywhere (13 §8).
            [$period, $plan] = $this->drafter->plan($locks, $in, $runId);
            $run = $locks->document(DepreciationRun::query()->where('company_id', $in->companyId)->findOrFail($runId), $version);
            if ($run->status !== DocumentStatus::Draft) {
                throw DomainRuleViolation::single('document_not_draft', 'Only a draft can be edited. Return a submitted run to draft first; a posted run is reversed, not edited.');
            }

            $before = $run->load('lines')->toArray();
            $run->lines()->delete();
            $run->forceFill([
                'fiscal_period_id' => $period->id, 'as_of' => $period->ends_on, 'total' => $plan->total, 'memo' => $in->memo, 'lock_version' => $run->lock_version + 1,
            ]);
            try {
                $run->save();
            } catch (UniqueConstraintViolationException) {
                throw DomainRuleViolation::single('depreciation_run_exists', 'This period already has a depreciation run. Void or reverse it first.');
            }
            $no = $this->drafter->writeLines($actor, $run, $period, $plan);
            $run->refresh()->load('lines');
            $audit->add('depreciation_run.updated', $run, before: $before, after: $run, context: ['assets' => $no, 'total' => $plan->total]);

            return $run;
        });
    }
}
