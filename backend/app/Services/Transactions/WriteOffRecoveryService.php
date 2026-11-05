<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Actions\Transactions\Inputs\WriteOffRecoveryDraftInput;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Shared\Fx;
use App\Domain\Trade\ProposedJournal;
use App\Domain\Transactions\WriteOffRecoveryPosting;
use App\Domain\Workflow\DocumentStatus;
use App\Models\Organisation\Company;
use App\Models\Trade\Contact;
use App\Models\Trade\Invoice;
use App\Models\Transactions\WriteOff;
use App\Models\Transactions\WriteOffRecovery;
use App\Services\Trade\PostingAccountResolver;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** What a recovery after write-off needs: the checks against its write-off, the base carried, and the journal. */
final class WriteOffRecoveryService
{
    public function __construct(private readonly PostingAccountResolver $accounts, private readonly WriteOffService $writeOffs) {}

    /** @return array<string, mixed> columns of the draft */
    public function header(WriteOffRecoveryDraftInput $in, Company $company): array
    {
        $w = WriteOff::query()->where('company_id', $company->id)->find($in->writeOffId)
            ?? throw DomainRuleViolation::single('not_found', 'The write-off does not exist.');
        if (trim($in->reason) === '') {
            throw DomainRuleViolation::single('recovery_reason_required', 'Say how the debt came back.');
        }
        $this->assertRecoverable($w, $in->amountFc, $in->date);

        return [
            'write_off_id' => $w->id, 'invoice_id' => $w->invoice_id, 'contact_id' => $w->contact_id, 'recovery_date' => $in->date, 'currency' => $w->currency,
            'fx_rate' => (string) $w->fx_rate, 'amount_fc' => $in->amountFc, 'amount' => $this->carried($w, $in->amountFc),
            'reason' => trim($in->reason), 'reference' => $in->reference, 'memo' => $in->memo,
        ];
    }

    /** The write-off must be posted, the date no earlier than it, and the amount no more than what has not been recovered yet. */
    public function assertRecoverable(WriteOff $w, int $amountFc, DateTimeImmutable $date): void
    {
        if ($w->status !== DocumentStatus::Posted) {
            throw DomainRuleViolation::single('recovery_write_off_not_posted', 'Only a posted write-off can be recovered.');
        }
        if ($date->format('Y-m-d') < $w->write_off_date->format('Y-m-d')) {
            throw DomainRuleViolation::single('recovery_before_write_off', 'The recovery cannot be dated before the write-off.');
        }
        if ($amountFc < 1 || $amountFc > $w->amount_fc - $w->recovered_fc) {
            throw DomainRuleViolation::single('recovery_exceeds_written_off', 'The recovery is more than what was written off and not yet recovered.');
        }
    }

    /** Base carried at the write-off's rate; the recovery that completes the write-off takes the remaining base (nothing is left over). */
    public function carried(WriteOff $w, int $amountFc): int
    {
        if ($w->recovered_fc + $amountFc >= $w->amount_fc) {
            return max(1, $w->amount - $this->recoveredBase($w));
        }

        return max(1, Fx::toBase($amountFc, (string) $w->fx_rate, 2, 2));
    }

    /** Base amount of this write-off's recoveries that are posted and not reversed. */
    public function recoveredBase(WriteOff $w): int
    {
        return (int) DB::table('write_off_recoveries')->where('write_off_id', $w->id)->where('status', 'posted')->sum('amount');
    }

    /** @return array{carried: int, proposed: ProposedJournal} */
    public function plan(WriteOffRecovery $r, WriteOff $w, Invoice $invoice, Company $company, string $number): array
    {
        $recoveryRef = $this->accounts->required($company, 'bad_debt_recovery', 'bad-debt recovery');
        $contact = Contact::query()->where('company_id', $company->id)->withTrashed()->whereKey($invoice->contact_id)->firstOrFail();
        $ar = $contact->ar_account_id ?? $this->accounts->required($company, 'ar_control', 'receivables control');
        $carried = $this->carried($w, $r->amount_fc);
        $proposed = WriteOffRecoveryPosting::journal(
            $r->id, $number, $r->recovery_date->format('Y-m-d'), (string) $invoice->number, (string) $w->number, $invoice->contact_id, $ar, $recoveryRef,
            $invoice->currency, (string) $w->fx_rate, $r->amount_fc, $carried, $company->base_currency, $r->reason,
        );

        return ['carried' => $carried, 'proposed' => $proposed];
    }

    public function toDraft(string $companyId, ProposedJournal $p, string $base): JournalDraft
    {
        return $this->writeOffs->toDraft($companyId, $p, $base);
    }
}
