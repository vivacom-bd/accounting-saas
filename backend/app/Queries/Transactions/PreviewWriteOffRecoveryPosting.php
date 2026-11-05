<?php

declare(strict_types=1);

namespace App\Queries\Transactions;

use App\Domain\Ledger\PostingRejected;
use App\Domain\Ledger\PostingValidator;
use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Shared\Violation;
use App\Domain\Workflow\DocumentStatus;
use App\Models\Fiscal\FiscalPeriod;
use App\Models\Organisation\Company;
use App\Models\Trade\Invoice;
use App\Models\Transactions\WriteOff;
use App\Models\Transactions\WriteOffRecovery;
use App\Queries\Trade\InvoicePostingPreview;
use App\Services\Ledger\PostingEngine;
use App\Services\Transactions\WriteOffRecoveryService;
use App\Support\Actor;
use DateTimeImmutable;

/** The journal a recovery would post (receivable back, bad-debt recovery credited) and the validator's verdict, without locks. */
final class PreviewWriteOffRecoveryPosting
{
    public function __construct(
        private readonly PostingEngine $engine,
        private readonly PostingValidator $validator,
        private readonly WriteOffRecoveryService $service,
    ) {}

    public function of(Actor $actor, string $recoveryId): InvoicePostingPreview
    {
        $r = WriteOffRecovery::query()->findOrFail($recoveryId);
        $violations = [];
        if (! in_array($r->status, [DocumentStatus::Draft, DocumentStatus::PendingApproval], true)) {
            $violations[] = new Violation('document_not_postable', "A {$r->status->value} recovery cannot be posted.");
        }
        $day = $r->recovery_date->format('Y-m-d');
        $period = FiscalPeriod::query()->where('company_id', $r->company_id)->where('kind', 'regular')->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)->first();
        if ($period === null) {
            $violations[] = new Violation('period_missing', 'No fiscal period covers the date.');
        }
        $lines = [];
        $debit = $credit = 0;
        try {
            $company = Company::query()->findOrFail($r->company_id);
            $w = WriteOff::query()->findOrFail($r->write_off_id);
            $invoice = Invoice::query()->findOrFail($r->invoice_id);
            $this->service->assertRecoverable($w, $r->amount_fc, DateTimeImmutable::createFromInterface($r->recovery_date));
            $plan = $this->service->plan($r, $w, $invoice, $company, $r->number ?? 'Draft');
            $draft = $this->service->toDraft($company->id, $plan['proposed'], $company->base_currency);
            $lines = $draft->lines;
            if ($period !== null) {
                try {
                    $decided = $this->validator->validate($draft, $this->engine->context($actor, $draft, $period));
                    $debit = $decided->totalDebit;
                    $credit = $decided->totalCredit;
                } catch (PostingRejected $e) {
                    array_push($violations, ...$e->violations);
                }
            }
        } catch (DomainRuleViolation $e) {
            array_push($violations, ...($e->violations !== [] ? $e->violations : [new Violation($e->errorCode, $e->getMessage())]));
        }

        return new InvoicePostingPreview($violations === [], $violations, $period?->id, $period?->name, $period?->status->value, $debit, $credit, $lines);
    }
}
