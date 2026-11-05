<?php

declare(strict_types=1);

namespace App\Actions\Transactions;

use App\Actions\Ledger\SetupGuard;
use App\Actions\Transactions\Inputs\WriteOffRecoveryDraftInput;
use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Workflow\DocumentStatus;
use App\Models\Organisation\Company;
use App\Models\Transactions\WriteOffRecovery;
use App\Services\Transactions\WriteOffRecoveryService;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/** Creates (no id) or replaces (id and version) a draft recovery. People only: `write_offs.create`. */
final class SaveWriteOffRecoveryDraft
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly SetupGuard $guard,
        private readonly WriteOffRecoveryService $service,
    ) {}

    public function handle(Actor $actor, WriteOffRecoveryDraftInput $in, ?string $recoveryId = null, ?int $lockVersion = null): WriteOffRecovery
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $in, $recoveryId, $lockVersion) {
            $this->guard->require($actor, 'write_offs.create', $in->companyId);
            $header = $this->service->header($in, Company::query()->findOrFail($in->companyId));

            if ($recoveryId === null) {
                $r = new WriteOffRecovery;
                $r->forceFill([
                    'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $in->companyId, 'status' => DocumentStatus::Draft,
                    ...$header, 'created_by' => $actor->id, 'lock_version' => 0,
                ])->save();
                $audit->add('write_off_recovery.created', $r, after: $r);

                return $r;
            }
            $r = $locks->document(WriteOffRecovery::query()->where('company_id', $in->companyId)->findOrFail($recoveryId), $lockVersion);
            if ($r->status !== DocumentStatus::Draft) {
                throw DomainRuleViolation::single('document_not_editable', 'Only a draft can be edited.');
            }
            $before = $r->attributesToArray();
            $r->forceFill([...$header, 'lock_version' => $r->lock_version + 1])->save();
            $audit->add('write_off_recovery.updated', $r, before: $before, after: $r);

            return $r;
        });
    }
}
