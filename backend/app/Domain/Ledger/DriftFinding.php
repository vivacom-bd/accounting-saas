<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/**
 * One mismatch the drift detector found (13 §9). Amounts are base minor units. See the `ledger_drift_findings` migration for
 * what "expected" and "stored" mean per kind.
 */
final readonly class DriftFinding
{
    public const string BALANCE = 'balance';

    public const string JOURNAL = 'journal';

    public const string TRIAL_BALANCE = 'trial_balance';

    public function __construct(
        public string $kind,
        public string $companyId,
        public ?string $accountId,
        public ?string $fiscalPeriodId,
        public ?string $journalId,
        public ?string $dimensions,
        public int $expectedDebit,
        public int $expectedCredit,
        public int $storedDebit,
        public int $storedCredit,
    ) {}

    /** Same mismatch, same fingerprint: a rerun over an unchanged ledger finds nothing new. */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->kind, $this->companyId, $this->accountId ?? '', $this->fiscalPeriodId ?? '', $this->journalId ?? '', $this->dimensions ?? '',
            $this->expectedDebit, $this->expectedCredit, $this->storedDebit, $this->storedCredit,
        ]));
    }
}
