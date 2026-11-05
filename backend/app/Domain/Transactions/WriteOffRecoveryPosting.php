<?php

declare(strict_types=1);

namespace App\Domain\Transactions;

use App\Domain\Ledger\JournalType;
use App\Domain\Trade\ProposedJournal;
use App\Domain\Trade\ProposedLine;

/**
 * Recovery after write-off to journal (D15 items 2 and 9): Dr receivable control with the contact (the debt is owed again, in the
 * invoice's currency at the write-off's carrying rate), Cr bad-debt recovery. Bad-debt expense and the allowance are not touched,
 * and no revenue is booked. Pure.
 */
final class WriteOffRecoveryPosting
{
    public static function journal(
        string $id, string $number, string $date, string $invoiceNumber, string $writeOffNumber, string $contactId, string $arControl, string $recoveryRef,
        string $currency, string $rate, int $amountFc, int $carriedBase, string $baseCurrency, string $reason,
    ): ProposedJournal {
        $foreign = $currency !== $baseCurrency;
        $lines = [
            new ProposedLine(
                $arControl, $carriedBase, 0, $currency, $foreign ? $rate : '1', $foreign ? $amountFc : $carriedBase, [],
                "Recovery of {$invoiceNumber} after write-off {$writeOffNumber}", $contactId,
            ),
            new ProposedLine($recoveryRef, 0, $carriedBase, $baseCurrency, '1', $carriedBase, [], "Bad debt recovered: {$invoiceNumber}"),
        ];

        return new ProposedJournal(JournalType::Sales, $date, $number, "Recovery {$number} of {$invoiceNumber}: {$reason}", 'write_off_recovery', $id, $lines);
    }
}
