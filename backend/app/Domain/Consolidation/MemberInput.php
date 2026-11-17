<?php

declare(strict_types=1);

namespace App\Domain\Consolidation;

/**
 * One member's figures for a run, as plain data. Amounts are net (debit minus credit) minor units of the member's own
 * currency, keyed by group account id. Closing journals are left out of every figure (they only move profit and loss
 * into retained earnings inside the member's books, which the worksheet does itself).
 *
 *  - `bs`: balances at the run's end of every mapped account that is neither profit and loss nor mapped to retained earnings.
 *  - `pl`: movement of the profit and loss accounts in the member's window. A subsidiary acquired inside the run starts
 *    its window the day after acquisition, so earlier results never enter (D16.6).
 *  - `equityMovements`: every movement of an equity account in the window (retained earnings included, such as a dividend),
 *    per day, with the rate of that day: equity keeps its historical rate (D16.4). `rate` is null for a member in the
 *    presentation currency, which needs none.
 *  - `opening`: for a member translated into another currency, its equity at the start of the window, local and translated
 *    (from the previous run, or at the acquisition date and rate), and the translation reserve then.
 *
 * @phpstan-type Opening array{local: array<string, int>, translated: array<string, int>, tr: int}
 * @phpstan-type EquityMovement array{account_id: string, date: string, net: int, rate: string|null}
 */
final readonly class MemberInput
{
    /**
     * @param  array<string, int>  $bs
     * @param  array<string, int>  $pl
     * @param  list<EquityMovement>  $equityMovements
     * @param  Opening|null  $opening
     */
    public function __construct(
        public string $id,
        public bool $isParent,
        public string $currency,
        public int $scale,
        public ?string $closingRate,
        public ?string $averageRate,
        public array $bs,
        public array $pl,
        public array $equityMovements = [],
        public ?array $opening = null,
    ) {}
}
