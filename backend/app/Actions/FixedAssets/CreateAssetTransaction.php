<?php

declare(strict_types=1);

namespace App\Actions\FixedAssets;

use App\Actions\FixedAssets\Inputs\AssetTransactionInput;
use App\Actions\Ledger\SetupGuard;
use App\Contracts\IdGenerator;
use App\Domain\FixedAssets\AssetPermissions;
use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Workflow\DocumentStatus;
use App\Models\FixedAssets\AssetTransaction;
use App\Services\FixedAssets\AssetJournals;
use App\Services\FixedAssets\AssetTransactionDrafter;
use App\Services\FixedAssets\AssetTransactionPlanner;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/**
 * Drafts a disposal, impairment, impairment reversal or revaluation: works out the figures, checks the journal could be built
 * (so a missing posting account is refused now, not at approval), and stores them per asset. Nothing posts here.
 * Disposals need `fixed_assets.dispose`; the others `fixed_assets.revalue`. UpdateAssetTransaction edits a draft the same way.
 */
final class CreateAssetTransaction
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly SetupGuard $guard,
        private readonly AssetTransactionPlanner $planner,
        private readonly AssetTransactionDrafter $drafter,
        private readonly AssetJournals $journals,
    ) {}

    public function handle(Actor $actor, AssetTransactionInput $in): AssetTransaction
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $in) {
            $permission = AssetPermissions::FOR_KIND[$in->kind] ?? throw DomainRuleViolation::single('asset_transaction_kind_invalid', 'Unknown kind.');
            $this->guard->require($actor, $permission, $in->companyId);
            if ($in->reason === null || trim($in->reason) === '') {
                if ($in->kind !== 'disposal') {
                    throw DomainRuleViolation::single('reason_required', 'Give the reason.');
                }
            }

            $plan = $this->planner->plan($in->companyId, $in->kind, $in->txDate, $in->categoryId, $in->lines, $in->params);

            $tx = new AssetTransaction;
            $tx->forceFill([
                'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $in->companyId, 'kind' => $in->kind,
                'status' => DocumentStatus::Draft, 'tx_date' => $in->txDate, 'category_id' => $in->kind === 'revaluation' ? $in->categoryId : null,
                'amount' => $this->drafter->size($in->kind, $plan), 'reason' => $in->reason, 'params' => $in->params, 'created_by' => $actor->id, 'lock_version' => 0,
            ])->save();
            $no = $this->drafter->writeLines($actor, $tx, $plan);
            $tx->refresh()->load('lines');
            // Fail early on a missing posting account or an unusable proceeds account.
            $this->journals->transaction($tx, $plan, 'DRAFT');
            $audit->add('asset_transaction.created', $tx, after: $tx, context: ['kind' => $in->kind, 'assets' => $no]);

            return $tx;
        });
    }
}
