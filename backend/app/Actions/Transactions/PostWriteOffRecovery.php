<?php

declare(strict_types=1);

namespace App\Actions\Transactions;

use App\Contracts\Clock;
use App\Domain\Ledger\JournalType;
use App\Domain\Ledger\PostingRejected;
use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Shared\Violation;
use App\Domain\Workflow\DocumentStatus;
use App\Events\Transactions\TransactionEvent;
use App\Models\Organisation\Company;
use App\Models\Trade\Invoice;
use App\Models\Transactions\WriteOff;
use App\Models\Transactions\WriteOffRecovery;
use App\Services\Ledger\JournalSetPoster;
use App\Services\Ledger\PostingEngine;
use App\Services\Transactions\WriteOffRecoveryService;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\RetryUnitOfWork;
use App\Support\UnitOfWork\UnitOfWork;
use DateTimeImmutable;

/**
 * Posts an approved recovery after write-off. Lock order (13 §8): period, the recovery, its write-off (so two recoveries cannot both
 * take the same remainder), its invoice, then the recovery's number and the journal's, one balance upsert. The invoice's `settled`
 * falls by the carried amount so it reopens, and the write-off's `recovered_fc` rises. Called by SubmitDocument / ApproveDocument.
 */
final class PostWriteOffRecovery
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly PostingEngine $engine,
        private readonly JournalSetPoster $poster,
        private readonly WriteOffRecoveryService $service,
        private readonly Clock $clock,
    ) {}

    public function handle(Actor $actor, string $recoveryId, ?int $lockVersion = null): WriteOffRecovery
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $recoveryId, $lockVersion) {
            $seen = WriteOffRecovery::query()->findOrFail($recoveryId);
            $period = $this->engine->lockPeriod($locks, $seen->company_id, JournalType::Sales, DateTimeImmutable::createFromInterface($seen->recovery_date), null); // 1
            $r = $locks->document($seen, $lockVersion);                                                                                                           // 2 (24)
            if ($r->recovery_date->format('Y-m-d') !== $seen->recovery_date->format('Y-m-d')) {
                throw new RetryUnitOfWork('Recovery date changed while locking.');
            }
            if (! in_array($r->status, [DocumentStatus::Draft, DocumentStatus::PendingApproval], true)) {
                throw DomainRuleViolation::single('document_not_postable', "A {$r->status->value} recovery cannot be posted.");
            }
            if ($period === null) {
                throw PostingRejected::because([new Violation('period_missing', 'No fiscal period covers the recovery date.')]);
            }
            $w = $locks->document(WriteOff::query()->findOrFail($r->write_off_id));                                                                               // 2 (26)
            $invoice = $locks->document(Invoice::query()->findOrFail($r->invoice_id));                                                                             // 2 (30)
            $this->service->assertRecoverable($w, $r->amount_fc, DateTimeImmutable::createFromInterface($r->recovery_date));
            $company = Company::query()->findOrFail($r->company_id);

            $year = $period->fiscalYear()->firstOrFail()->name;
            $number = $locks->sequence($r->company_id, 'doc:write_off_recovery:'.$year, 'WOR-'.$year.'-');                                                         // 3
            $plan = $this->service->plan($r, $w, $invoice, $company, $number);
            $draft = $this->service->toDraft($company->id, $plan['proposed'], $company->base_currency);
            $journal = $this->poster->post($actor, $locks, $audit, $outbox, $period, $company->base_currency, [$draft])[0];                                        // 3, 4

            $invoice->forceFill(['settled_fc' => $invoice->settled_fc - $r->amount_fc, 'settled' => $invoice->settled - $plan['carried'], 'lock_version' => $invoice->lock_version + 1])->save();
            $w->forceFill(['recovered_fc' => $w->recovered_fc + $r->amount_fc, 'lock_version' => $w->lock_version + 1])->save();
            $before = $r->attributesToArray();
            $r->forceFill([
                'status' => DocumentStatus::Posted, 'number' => $number, 'journal_id' => $journal->id, 'amount' => $plan['carried'],
                'posted_at' => $this->clock->now(), 'posted_by' => $actor->id, 'lock_version' => $r->lock_version + 1,
            ])->save();
            $audit->add('write_off_recovery.posted', $r, before: $before, after: $r, context: ['journal_id' => $journal->id, 'invoice_id' => $invoice->id, 'write_off_id' => $w->id]);
            $outbox->add(new TransactionEvent('write_off_recovery.posted', $r->id, $r->company_id, $r->lock_version, ['number' => $number, 'invoice_id' => $invoice->id]));

            return $r;
        });
    }
}
