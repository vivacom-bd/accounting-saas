<?php

declare(strict_types=1);

namespace App\Domain\Consolidation;

/**
 * The worksheet of a run: each member's figures in its own currency and translated, every adjustment as a labelled
 * cell, and the consolidated total per group account. Amounts are net (debit − credit) minor units.
 *
 * `cells` hold every adjustment; `source` says where it came from ('entry' = a posted consolidation entry; later steps add
 * their own), `entry_id` is set for posted entries so a cell can drill to its entry. `summary` holds the consolidated
 * `profit` and the period's `translation_difference` (the movement of the translation reserve, other comprehensive income).
 *
 * @phpstan-type Cell array{source: string, member_id: string|null, account_id: string, net: int, entry_id: string|null}
 * @phpstan-type State array{local: array<string, int>, translated: array<string, int>, tr: int}
 * @phpstan-type Proposed array{kind: string, member_id: string|null, memo: string, lines: list<array{account_id: string, member_id: string|null, net: int}>}
 */
final readonly class RunResult
{
    /**
     * @param  array<string, array<string, int>>  $local  member id → group account → net
     * @param  array<string, array<string, int>>  $translated
     * @param  list<Cell>  $cells
     * @param  array<string, int>  $consolidated  group account → net
     * @param  array<string, State>  $states  closing state per member, the opening of the next run
     * @param  list<Proposed>  $proposed  entries the run asks to post when approved
     * @param  array<string, int>  $summary
     */
    public function __construct(
        public array $local,
        public array $translated,
        public array $cells,
        public array $consolidated,
        public array $states,
        public array $proposed,
        public array $summary,
    ) {}

    public function isBalanced(): bool
    {
        return array_sum($this->consolidated) === 0;
    }
}
