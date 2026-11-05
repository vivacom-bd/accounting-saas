<?php

declare(strict_types=1);

namespace App\Actions\Transactions\Inputs;

use DateTimeImmutable;

/** A draft recovery: one posted write-off, the date, the amount in the invoice's currency (up to what is not yet recovered) and the reason. */
final readonly class WriteOffRecoveryDraftInput
{
    public function __construct(
        public string $companyId,
        public string $writeOffId,
        public DateTimeImmutable $date,
        public int $amountFc,
        public string $reason,
        public ?string $reference = null,
        public ?string $memo = null,
    ) {}
}
