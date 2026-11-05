<?php

declare(strict_types=1);

namespace App\Queries\Consolidation;

/** A member account that carries a balance or movement and has no mapping (amounts in the member's currency, minor units). */
final readonly class UnmappedBalance
{
    public function __construct(
        public string $memberId,
        public string $companyId,
        public string $accountId,
        public string $code,
        public string $name,
        public string $accountType,
        /** Debit minus credit at the end date. */
        public int $net,
        public int $movementDebit,
        public int $movementCredit,
    ) {}
}
