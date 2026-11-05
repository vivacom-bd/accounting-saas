<?php

declare(strict_types=1);

namespace App\Actions\EInvoicing;

use App\Actions\Ledger\SetupGuard;
use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\EInvoicing\EInvoiceStatus;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\EInvoicing\CompanyEInvoicing;
use App\Models\EInvoicing\EInvoiceSubmission;
use App\Models\Organisation\Company;
use App\Services\EInvoicing\EInvoiceAdapters;
use App\Services\EInvoicing\EInvoiceQueue;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use App\Support\UnitOfWork\VersionConflict;

/**
 * E-invoicing settings and manual retry (`einvoicing.manage`, people only; plan 3c.27). Off for every company by default. It can be
 * turned on only with an adapter that is installed, configured and allowed here, and the seller's tax id: nothing is sent to an authority
 * otherwise. Turning it off stops queuing new documents; submissions already queued still finish.
 */
final class ManageEInvoicing
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
        private readonly SetupGuard $guard,
        private readonly EInvoiceAdapters $adapters,
        private readonly EInvoiceQueue $queue,
    ) {}

    public function update(Actor $actor, string $companyId, ?int $version, bool $enabled, ?string $adapterKey, ?string $sellerTaxId, ?string $branch): CompanyEInvoicing
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $companyId, $version, $enabled, $adapterKey, $sellerTaxId, $branch) {
            $this->guard->require($actor, 'einvoicing.manage', $companyId);
            Company::query()->whereKey($companyId)->lock('FOR NO KEY UPDATE')->firstOrFail();
            $row = CompanyEInvoicing::query()->where('company_id', $companyId)->lock('FOR UPDATE')->first();
            if ($row !== null && $version !== null && $row->lock_version !== $version) {
                throw new VersionConflict($row->lock_version, $version);
            }

            $adapterKey = $adapterKey === null || trim($adapterKey) === '' ? null : trim($adapterKey);
            $sellerTaxId = $sellerTaxId === null || trim($sellerTaxId) === '' ? null : trim($sellerTaxId);
            if ($enabled) {
                if ($adapterKey === null || $this->adapters->find($adapterKey) === null) {
                    throw DomainRuleViolation::single('einvoice_adapter_unavailable', 'E-invoicing can be turned on only with an adapter that is installed and configured. None is available for that choice.');
                }
                if ($sellerTaxId === null) {
                    throw DomainRuleViolation::single('einvoice_seller_tax_id_required', 'Enter the company’s tax id before turning e-invoicing on.');
                }
            }

            $before = $row?->attributesToArray();
            if ($row === null) {
                $row = new CompanyEInvoicing;
                $row->forceFill(['id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $companyId]);
            }
            $row->forceFill([
                'enabled' => $enabled, 'adapter_key' => $adapterKey, 'seller_tax_id' => $sellerTaxId, 'seller_branch' => $branch === null || trim($branch) === '' ? null : trim($branch),
                'changed_by' => $actor->id, 'lock_version' => $row->exists ? $row->lock_version + 1 : 0,
            ])->save();
            $audit->add('einvoicing.settings_changed', $row, before: $before, after: $row);

            return $row;
        });
    }

    /** Asks for another attempt on a failed or unanswered submission (also one that was dead-lettered). */
    public function retry(Actor $actor, string $companyId, string $invoiceId): EInvoiceSubmission
    {
        $submission = $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $companyId, $invoiceId) {
            $this->guard->require($actor, 'einvoicing.manage', $companyId);
            $s = EInvoiceSubmission::query()->where('company_id', $companyId)->where('invoice_id', $invoiceId)->orderByDesc('document_version')->lock('FOR UPDATE')->first()
                ?? throw DomainRuleViolation::single('not_found', 'This document has not been sent to a tax authority.');
            if (! EInvoiceStatus::from($s->status)->canRetry()) {
                throw DomainRuleViolation::single('einvoice_not_retryable', 'Only a failed or unanswered submission can be retried. An accepted or rejected one is final.');
            }
            $before = ['status' => $s->status, 'attempts' => $s->attempts, 'dead_lettered_at' => $s->dead_lettered_at?->format('c')];
            $s->forceFill([
                'attempts' => $s->dead_lettered_at !== null ? 0 : $s->attempts, 'dead_lettered_at' => null,
                'next_attempt_at' => $this->clock->now()->format('Y-m-d H:i:sP'), 'lock_version' => $s->lock_version + 1,
            ])->save();
            $audit->add('einvoice.retry_requested', $s, before: $before, after: ['status' => $s->status]);

            return $s;
        });
        $this->queue->push($actor->tenantId, $submission->id);

        return $submission;
    }
}
