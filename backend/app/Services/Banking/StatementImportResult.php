<?php

declare(strict_types=1);

namespace App\Services\Banking;

use App\Models\Banking\BankStatement;

/** What an import did, for the response. */
final readonly class StatementImportResult
{
    /**
     * @param  list<string>  $warnings
     * @param  list<array{line: int, reference: string|null, status: string|null, reason: string}>  $skippedRows  rows left out on purpose (M-Pesa rows that are not completed)
     */
    public function __construct(
        public ?BankStatement $statement,
        public int $linesImported,
        public int $duplicateLinesSkipped,
        public bool $alreadyImported,
        public array $warnings,
        public array $skippedRows = [],
    ) {}
}
