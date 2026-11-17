<?php

declare(strict_types=1);

namespace App\Actions\FixedAssets;

use App\Actions\FixedAssets\Inputs\AssetTransactionInput;
use App\Actions\Ledger\SetupGuard;
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
 * Edits a draft disposal, impairment, impairment reversal or revaluation in place: date, amounts, assets, reason. The figures
 * are worked out again as when drafting and the lines replaced. The kind cannot change (it decides who may draft it); start a
 * new draft for that. Only a draft is editable: a submitted one is returned first, a posted one is reversed. Optimistic lock
 * on `version`; one audit event with the before and after.
 */
final class UpdateAssetTransaction
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly SetupGuard $guard,
        private readonly AssetTransactionPlanner $planner,
        private readonly AssetTransactionDrafter $drafter,
        private readonly AssetJournals $journals,
    ) {}

    public function handle(Actor $actor, string $txId, AssetTransactionInput $in, int $version): AssetTransaction
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $txId, $in, $version) {
            $permission = AssetPermissions::FOR_KIND[$in->kind] ?? throw DomainRuleViolation::single('asset_transaction_kind_invalid', 'Unknown kind.');
            $this->guard->require($actor, $permission, $in->companyId);
            $tx = $locks->document(AssetTransaction::query()->where('company_id', $in->companyId)->findOrFail($txId), $version);
            if ($tx->status !== DocumentStatus::Draft) {
                throw DomainRuleViolation::single('document_not_draft', 'Only a draft can be edited. Return a submitted document to draft first; a posted one is reversed, not edited.');
            }
            if ($tx->kind !== $in->kind) {
                throw DomainRuleViolation::single('asset_transaction_kind_locked', 'The kind of a draft cannot change. Void it and start a new one.');
            }
            if ($in->reason === null || trim($in->reason) === '') {
                if ($in->kind !== 'disposal') {
                    throw DomainRuleViolation::single('reason_required', 'Give the reason.');
                }
            }

            $plan = $this->planner->plan($in->companyId, $in->kind, $in->txDate, $in->categoryId, $in->lines, $in->params);

            $before = $tx->load('lines')->toArray();
            $tx->lines()->delete();
            $tx->forceFill([
                'tx_date' => $in->txDate, 'category_id' => $in->kind === 'revaluation' ? $in->categoryId : null, 'amount' => $this->drafter->size($in->kind, $plan),
                'reason' => $in->reason, 'params' => $in->params, 'lock_version' => $tx->lock_version + 1,
            ])->save();
            $no = $this->drafter->writeLines($actor, $tx, $plan);
            $tx->refresh()->load('lines');
            $this->journals->transaction($tx, $plan, 'DRAFT');
            $audit->add('asset_transaction.updated', $tx, before: $before, after: $tx, context: ['kind' => $in->kind, 'assets' => $no]);

            return $tx;
        });
    }
}
