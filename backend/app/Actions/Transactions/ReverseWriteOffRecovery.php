<?php

declare(strict_types=1);

namespace App\Actions\Transactions;

use App\Actions\Ledger\Inputs\ReverseJournalInput;
use App\Actions\Ledger\ReverseJournal;
use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Workflow\DocumentStatus;
use App\Events\Transactions\TransactionEvent;
use App\Models\Trade\Invoice;
use App\Models\Transactions\WriteOff;
use App\Models\Transactions\WriteOffRecovery;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use DateTimeImmutable;

/**
 * Reverses a posted recovery: the mirror journal comes from ReverseJournal (D3 dating, closed periods refused), the invoice is
 * written off again for that amount and the write-off's `recovered_fc` falls. Refused when a receipt or credit has already used the
 * reopened amount, because the invoice would then be settled for more than it is worth. People only: `write_offs.reverse`.
 */
final class ReverseWriteOffRecovery
{
    public function __construct(private readonly UnitOfWork $uow, private readonly ReverseJournal $journals) {}

    public function handle(Actor $actor, string $recoveryId, ?DateTimeImmutable $date = null, ?int $lockVersion = null, ?string $memo = null): WriteOffRecovery
    {
        if ($actor->isApiClient()) {
            throw DomainRuleViolation::single('forbidden', 'Only a person can reverse a recovery.');
        }

        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $recoveryId, $date, $lockVersion, $memo) {
            $seen = WriteOffRecovery::query()->findOrFail($recoveryId);
            $this->assertReversible($seen);
            $journalId = $seen->journal_id ?? throw DomainRuleViolation::single('recovery_not_reversible', 'The recovery has no journal.');

            $locked = null;
            $w = null;
            $invoice = null;
            $reversal = $this->journals->handle($actor, new ReverseJournalInput(
                journalId: $journalId, date: $date, lockVersion: null, memo: $memo, permission: 'write_offs.reverse',
                whileLocked: function (LockSet $set) use ($seen, $lockVersion, &$locked, &$w, &$invoice): void {
                    $locked = $set->document($seen, $lockVersion);
                    $this->assertReversible($locked);
                    $w = $set->document(WriteOff::query()->whereKey($locked->write_off_id)->firstOrFail());
                    $invoice = $set->document(Invoice::query()->whereKey($locked->invoice_id)->firstOrFail());
                    if ($invoice->amountDueFc() < $locked->amount_fc || $invoice->total - $invoice->wht_amount - $invoice->settled < $locked->amount) {
                        throw DomainRuleViolation::single('recovery_amount_used', 'A receipt or credit has already used the reopened amount; reverse that first.');
                    }
                },
            ));
            assert($locked instanceof WriteOffRecovery && $w instanceof WriteOff && $invoice instanceof Invoice);

            $invoice->forceFill([
                'settled_fc' => $invoice->settled_fc + $locked->amount_fc, 'settled' => $invoice->settled + $locked->amount, 'lock_version' => $invoice->lock_version + 1,
            ])->save();
            $w->forceFill(['recovered_fc' => $w->recovered_fc - $locked->amount_fc, 'lock_version' => $w->lock_version + 1])->save();
            $before = $locked->attributesToArray();
            $locked->forceFill(['status' => DocumentStatus::Reversed, 'reversal_journal_id' => $reversal->id, 'lock_version' => $locked->lock_version + 1])->save();
            $audit->add('write_off_recovery.reversed', $locked, before: $before, after: $locked, context: ['reversal_journal_id' => $reversal->id]);
            $outbox->add(new TransactionEvent('write_off_recovery.reversed', $locked->id, $locked->company_id, $locked->lock_version, ['reversal_journal_id' => $reversal->id]));

            return $locked;
        });
    }

    private function assertReversible(WriteOffRecovery $r): void
    {
        if ($r->status === DocumentStatus::Reversed) {
            throw DomainRuleViolation::single('recovery_already_reversed', 'The recovery has already been reversed.');
        }
        if ($r->status !== DocumentStatus::Posted) {
            throw DomainRuleViolation::single('recovery_not_reversible', 'Only a posted recovery can be reversed.');
        }
    }
}
