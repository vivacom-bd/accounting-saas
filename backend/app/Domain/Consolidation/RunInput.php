<?php

declare(strict_types=1);

namespace App\Domain\Consolidation;

/**
 * Everything a run needs, as plain data (D16.3): the group chart, each member's figures and the standing group entries.
 *
 * @phpstan-type AccountDef array{type: string, role: string|null}
 * @phpstan-type EntryLine array{entry_id: string|null, date: string, member_id: string|null, account_id: string, net: int}
 */
final readonly class RunInput
{
    /**
     * @param  array<string, AccountDef>  $accounts  group account id → type and role
     * @param  list<MemberInput>  $members
     * @param  list<EntryLine>  $entryLines  posted standing entry lines dated up to $to (net = debit − credit)
     * @param  int  $icTolerance  the largest intercompany difference that does not block approval, minor units of $currency (D16.7)
     * @param  array<string, int>  $icPrior  the balance and loan differences of the previous approved run, by "member a|member b|class"
     */
    public function __construct(
        public string $currency,
        public int $scale,
        public string $from,
        public string $to,
        public array $accounts,
        public array $members,
        public array $entryLines = [],
        public int $icTolerance = 0,
        public array $icPrior = [],
    ) {}

    public function roleAccount(string $role): ?string
    {
        foreach ($this->accounts as $id => $a) {
            if ($a['role'] === $role) {
                return (string) $id;
            }
        }

        return null;
    }

    public function isProfitAndLoss(string $accountId): bool
    {
        return GroupRoles::isProfitAndLoss($this->accounts[$accountId]['type'] ?? 'asset');
    }
}
