<?php

declare(strict_types=1);

namespace App\Services\Consolidation;

use App\Domain\Consolidation\GroupRoles;
use App\Domain\Consolidation\MemberInput;
use App\Domain\Consolidation\Rounding;
use App\Domain\Consolidation\RunInput;
use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Shared\Violation;
use App\Models\Consolidation\ConsolidationAccountMap;
use App\Models\Consolidation\ConsolidationEntry;
use App\Models\Consolidation\ConsolidationEntryLine;
use App\Models\Consolidation\ConsolidationGroup;
use App\Models\Consolidation\ConsolidationGroupAccount;
use App\Models\Consolidation\ConsolidationIcContact;
use App\Models\Consolidation\ConsolidationMember;
use App\Models\Consolidation\ConsolidationMemberOpening;
use App\Models\Consolidation\ConsolidationRateOverride;
use App\Models\Consolidation\ConsolidationRun;
use App\Models\Consolidation\ConsolidationRunMember;
use App\Models\Fiscal\FiscalPeriod;
use App\Models\Ledger\Account;
use App\Models\Organisation\Company;
use App\Models\Organisation\CompanySettings;
use App\Models\Platform\Currency;
use App\Queries\Consolidation\EquityMovements;
use App\Queries\Consolidation\IntercompanyLines;
use App\Queries\Consolidation\PeriodAverageRate;
use App\Queries\Consolidation\UnmappedBalances;
use App\Queries\Fx\FindExchangeRate;
use App\Queries\Reports\LedgerBalances;
use App\Support\Reports\Dates;
use App\Support\Reports\LedgerVersion;
use App\Support\Tenancy\TenantContext;

/**
 * Reads everything a run needs from the members' books and the consolidation ledger, read-only (D16.3). One member at a
 * time on the report connection under the tenant context; nothing is written to any company. Rates come from the parent
 * company's rate table, the group's rate source.
 *
 * Blocks, with the full list, on an unmapped account with a balance and on any missing rate (D16.2, D16.4). Refuses a gap
 * or an overlap with the approved runs, and a subsidiary acquired in a period already consolidated. Warns, without
 * refusing, for a member period that is not closed and for a closing rate struck before the run's end.
 *
 * Runs follow each other: a run starts the day after the previous approved run ended (the first run starts where the user
 * says). A subsidiary acquired inside a run enters it the day after acquisition (D16.6). A foreign subsidiary starts from its
 * equity at the acquisition date and rate; later runs start from the closing position the previous run stored. A foreign
 * subsidiary acquired before the group's first run has no such history, so that first run must start the day after its
 * acquisition.
 */
final class RunInputBuilder
{
    public function __construct(
        private readonly LedgerBalances $balances,
        private readonly FindExchangeRate $rates,
        private readonly PeriodAverageRate $averages,
        private readonly EquityMovements $equityMovements,
        private readonly LedgerVersion $versions,
        private readonly UnmappedBalances $unmapped,
        private readonly TenantContext $tenant,
        private readonly IntercompanyLines $icLines,
    ) {}

    public function prepare(string $groupId, string $from, string $to): PreparedRun
    {
        $group = ConsolidationGroup::query()->findOrFail($groupId);
        $tenantId = $this->tenant->require();
        $pres = $group->presentation_currency;
        $rateSource = $group->parent_company_id;

        $prior = ConsolidationRun::query()->where('group_id', $groupId)->where('status', 'approved')->orderByDesc('period_to')->first();
        if ($prior !== null) {
            $priorEnd = $prior->period_to->format('Y-m-d');
            if ($priorEnd >= $from) {
                throw DomainRuleViolation::single('period_already_consolidated', 'The period overlaps a run that is already approved; reverse that run first.');
            }
            if (Dates::after($priorEnd) !== $from) {
                throw DomainRuleViolation::single('consolidation_gap', 'Runs follow each other: the next period starts on '.Dates::after($priorEnd).'.');
            }
        }

        $scales = Currency::query()->pluck('minor_unit', 'code')->map(fn ($v): int => (int) $v)->all();
        $groupScale = $scales[$pres] ?? 2;
        $defs = [];
        foreach (ConsolidationGroupAccount::query()->where('group_id', $groupId)->get() as $a) {
            $defs[$a->id] = ['type' => $a->account_type, 'role' => $a->role];
        }
        $reAccount = null;
        foreach ($defs as $id => $d) {
            if ($d['role'] === GroupRoles::RETAINED_EARNINGS) {
                $reAccount = (string) $id;
            }
        }

        $blockers = [];
        $missingRates = [];
        $members = [];
        $meta = [];
        $warnings = [];
        foreach (ConsolidationMember::query()->where('group_id', $groupId)->with('company')->orderByDesc('role')->orderBy('id')->get() as $member) {
            $acquired = $member->acquisition_date?->format('Y-m-d');
            if ($member->status === 'disposed' || ($acquired !== null && $acquired >= $to)) {
                $warnings[] = ['code' => 'member_skipped', 'member_id' => $member->id, 'message' => 'The member is not part of the group in this period.'];

                continue;
            }
            $company = $member->company ?? throw DomainRuleViolation::single('not_found', 'A member company does not exist.');
            $entered = $prior !== null && ConsolidationRunMember::query()->where('member_id', $member->id)->where('run_id', $prior->id)->exists();
            $isNew = ! $member->isParent() && ! $entered;
            if ($isNew && $prior !== null && $acquired !== null && $acquired < $prior->period_to->format('Y-m-d')) {
                throw DomainRuleViolation::single('acquisition_in_consolidated_period', "Company {$company->name} was acquired in a period that is already consolidated.");
            }
            $windowFrom = $isNew && $acquired !== null && $acquired >= $from ? Dates::after($acquired) : $from;

            $currency = $company->base_currency;
            $scale = $scales[$currency] ?? 2;
            $foreign = $currency !== $pres;
            $closing = null;
            $average = null;
            $detail = [];
            if ($foreign) {
                $missing = fn (string $day): Violation => new Violation('fx_rate_missing', "No {$currency}/{$pres} rate on or before {$day}.");
                $quote = $this->rates->find($rateSource, $currency, $pres, $to);
                if ($quote === null) {
                    $missingRates["{$currency}@{$to}"] = $missing($to);
                } else {
                    $closing = $quote->rate;
                    $detail['closing'] = ['rate' => $quote->rate, 'date' => $quote->date];
                    if ($quote->date !== $to) {
                        $warnings[] = ['code' => 'closing_rate_dated_earlier', 'member_id' => $member->id, 'message' => "The closing {$currency}/{$pres} rate is the one struck on {$quote->date}, before {$to}."];
                    }
                }
                $override = ConsolidationRateOverride::query()->where('group_id', $groupId)->where('currency', $currency)
                    ->where('period_from', $from)->where('period_to', $to)->first();
                if ($override !== null) {
                    $average = $override->rate;
                    $detail['average'] = ['rate' => $override->rate, 'method' => 'override', 'reason' => $override->reason];
                } else {
                    $avg = ($this->averages)($rateSource, $currency, $pres, $windowFrom, $to);
                    if ($avg === null) {
                        $missingRates["{$currency}@{$windowFrom}"] = $missing($windowFrom);
                    } else {
                        $average = $avg['rate'];
                        $detail['average'] = ['rate' => $avg['rate'], 'method' => 'daily_mean', 'segments' => $avg['segments']];
                    }
                }
            }

            $maps = ConsolidationAccountMap::query()->where('member_id', $member->id)->pluck('group_account_id', 'account_id')->all();
            [$bs, $pl] = $this->figures($company->id, $maps, $defs, $reAccount, $windowFrom, $to);

            // Equity movements of the window, each at the rate of its own day (historical, D16.4).
            $equityAccounts = array_map('strval', array_keys(array_filter($maps, fn (string $g): bool => ($defs[$g]['type'] ?? '') === 'equity')));
            $moves = [];
            foreach (($this->equityMovements)($company->id, $equityAccounts, $windowFrom, $to) as $mv) {
                $rate = null;
                if ($foreign) {
                    $q = $this->rates->find($rateSource, $currency, $pres, $mv['date']);
                    if ($q === null) {
                        $missingRates["{$currency}@{$mv['date']}"] = new Violation('fx_rate_missing', "No {$currency}/{$pres} rate on or before {$mv['date']}.");
                    }
                    $rate = $q?->rate;
                }
                $moves[] = ['account_id' => $maps[$mv['account_id']], 'date' => $mv['date'], 'net' => $mv['net'], 'rate' => $rate];
            }

            $opening = null;
            $openingId = null;
            if ($foreign) {
                if ($isNew) {
                    $entered = ConsolidationMemberOpening::query()->where('member_id', $member->id)->first();
                    if ($entered !== null) {
                        $opening = $this->entered($entered, $member->id, $company, $maps, $defs, $reAccount, $from, $blockers);
                        $openingId = $entered->id;
                    } elseif ($acquired === null || $acquired < Dates::before($from)) {
                        $blockers[] = new Violation('consolidation_opening_missing', "Company {$company->name} keeps its books in {$currency} and was acquired on {$acquired}, before this first run: its equity has no historical rates. Start the first run on ".($acquired === null ? 'its acquisition' : Dates::after($acquired)).', or enter its opening translated equity from the last consolidation done before.');
                    } else {
                        $opening = $this->baseline($group, $member->id, $company->id, $maps, $defs, $reAccount, $acquired, $currency, $scale, $groupScale, $missingRates, $blockers);
                    }
                } else {
                    $state = ConsolidationRunMember::query()->where('member_id', $member->id)->where('run_id', $prior?->id)->first()?->state;
                    if (! is_array($state) || ! isset($state['translated'], $state['local'])) {
                        throw DomainRuleViolation::single('consolidation_opening_missing', "Company {$company->name} has no closing position from the previous run.");
                    }
                    $opening = ['local' => $this->ints($state['local']), 'translated' => $this->ints($state['translated']), 'tr' => (int) ($state['tr'] ?? 0)];
                }
            }

            $meta[$member->id] = [
                'company_id' => $company->id, 'currency' => $currency, 'window_from' => $windowFrom,
                'ledger_version' => $this->versions->current($tenantId, $company->id),
                'closing_rate' => $closing, 'average_rate' => $average, 'rate_detail' => $detail, 'opening_id' => $openingId,
            ];
            [$ic, $ni] = $this->intercompany($member->id, $company, $defs, $windowFrom, $to, $foreign ? fn (string $day): ?string => $this->dayRate($rateSource, $currency, $pres, $day, $missingRates) : null, $blockers);
            $members[] = new MemberInput($member->id, $member->isParent(), $currency, $scale, $closing, $average, $bs, $pl, $moves, $opening, $ic, $ni);

            foreach (FiscalPeriod::query()->where('company_id', $company->id)->where('kind', 'regular')
                ->where('ends_on', '>=', $windowFrom)->where('starts_on', '<=', $to)->where('status', '<>', 'closed')->orderBy('starts_on')->get() as $p) {
                $warnings[] = ['code' => 'period_not_closed', 'member_id' => $member->id, 'company_id' => $company->id, 'period' => $p->name, 'status' => $p->status->value, 'message' => "{$company->name}: {$p->name} is {$p->status->value}, not closed."];
            }
        }

        foreach (($this->unmapped)($groupId, $from, $to) as $u) {
            if (isset($meta[$u->memberId])) {
                $blockers[] = new Violation('unmapped_account', "Account {$u->code} {$u->name} of company {$u->companyId} has a balance and no group account.");
            }
        }
        $blockers = [...$blockers, ...array_values($missingRates)];
        if ($blockers !== []) {
            throw new DomainRuleViolation('consolidation_blocked', 'The run cannot be computed: '.count($blockers).' problem(s) to fix first.', $blockers);
        }

        $lines = [];
        // Standing entries only: a run's eliminations are worked out again by every run from the balances and the window's
        // results, so earlier ones (and their reversals) are not carried (D16.7).
        $entryLines = ConsolidationEntryLine::query()->join('consolidation_entries as e', 'e.id', '=', 'consolidation_entry_lines.entry_id')
            ->where('e.group_id', $groupId)->whereIn('e.status', ['posted', 'reversed'])->where('e.entry_date', '<=', $to)
            ->where('e.kind', '<>', 'elimination')
            ->where(fn ($q) => $q->whereNull('e.reverses_entry_id')->orWhereNotIn('e.reverses_entry_id', ConsolidationEntry::query()->where('group_id', $groupId)->where('kind', 'elimination')->select('id')))
            ->orderBy('e.entry_date')->orderBy('e.number')->orderBy('consolidation_entry_lines.line_no')
            ->get(['consolidation_entry_lines.*', 'e.entry_date as e_date', 'e.id as e_id']);
        foreach ($entryLines as $l) {
            $lines[] = [
                'entry_id' => (string) $l->getAttribute('e_id'), 'date' => substr((string) $l->getAttribute('e_date'), 0, 10), 'member_id' => $l->member_id,
                'account_id' => $l->group_account_id, 'net' => $l->debit - $l->credit,
            ];
        }

        return new PreparedRun(new RunInput($pres, $groupScale, $from, $to, $defs, $members, $lines, $group->ic_tolerance), $meta, $warnings);
    }

    /**
     * A member's intragroup items (D16.7): every line on an account the group flags intercompany whose counterparty is a
     * member (the account's own counterparty, else the member the line's contact stands for), summed per class,
     * counterparty and group account. Balance sheet accounts at the end, profit and loss over the window, equity (a
     * dividend paid) per day with that day's rate. Also, for loans designated part of the net investment, the exchange
     * difference the member booked in profit or loss over the window, on the group account of its unrealised FX account.
     *
     * @param  array<string, array{type: string, role: string|null}>  $defs
     * @param  (callable(string): ?string)|null  $dayRate  null for a member in the presentation currency
     * @param  list<Violation>  $blockers
     * @return array{0: list<array{class: string, counterparty: string, account_id: string, net: int, currencies: array<string, int>, moves: list<array{net: int, rate: string|null}>}>, 1: list<array{account_id: string, net: int}>}
     */
    private function intercompany(string $memberId, Company $company, array $defs, string $windowFrom, string $to, ?callable $dayRate, array &$blockers): array
    {
        $maps = ConsolidationAccountMap::query()->where('member_id', $memberId)->whereNotNull('ic_class')->get();
        if ($maps->isEmpty()) {
            return [[], []];
        }
        $contacts = ConsolidationIcContact::query()->where('member_id', $memberId)->pluck('counterparty_member_id', 'contact_id')->all();
        $byType = ['bs' => [], 'pl' => [], 'eq' => []];
        foreach ($maps as $map) {
            $type = $defs[$map->group_account_id]['type'] ?? 'asset';
            $byType[$type === 'equity' ? 'eq' : (GroupRoles::isProfitAndLoss($type) ? 'pl' : 'bs')][] = $map->account_id;
        }
        $rows = [
            ...($this->icLines)($company->id, $byType['bs'], null, $to),
            ...($this->icLines)($company->id, $byType['pl'], $windowFrom, $to),
            ...($this->icLines)($company->id, $byType['eq'], $windowFrom, $to, true),
        ];
        $byAccount = [];
        $loans = [];
        foreach ($maps as $map) {
            $byAccount[$map->account_id] = $map;
            if ($map->net_investment) {
                $loans[] = $map->account_id;
            }
        }
        $slices = [];
        foreach ($rows as $r) {
            $map = $byAccount[$r['account_id']] ?? null;
            if ($map === null) {
                continue;
            }
            $counterparty = $map->counterparty_member_id ?? ($r['contact_id'] === null ? null : ($contacts[$r['contact_id']] ?? null));
            if ($counterparty === null || ($r['net'] === 0 && $r['fc'] === 0)) {
                continue;
            }
            $key = "{$map->ic_class}|{$counterparty}|{$map->group_account_id}";
            $slices[$key] ??= ['class' => (string) $map->ic_class, 'counterparty' => (string) $counterparty, 'account_id' => $map->group_account_id, 'net' => 0, 'currencies' => [], 'moves' => []];
            $slices[$key]['net'] += $r['net'];
            $fc = $r['currency'] === $company->base_currency ? $r['net'] : $r['fc'];
            $slices[$key]['currencies'][$r['currency']] = ($slices[$key]['currencies'][$r['currency']] ?? 0) + $fc;
            if ($r['day'] !== null) {
                $slices[$key]['moves'][$r['day']] ??= ['net' => 0, 'rate' => $dayRate === null ? null : $dayRate($r['day'])];
                $slices[$key]['moves'][$r['day']]['net'] += $r['net'];
            }
        }
        $ic = [];
        foreach ($slices as $slice) {
            ksort($slice['moves']);
            ksort($slice['currencies']);
            $ic[] = [...$slice, 'moves' => array_values($slice['moves'])];
        }

        $ni = [];
        $moved = array_sum($this->icLines->revaluation($company->id, $loans, $windowFrom, $to));
        if ($moved !== 0) {
            $target = $this->unrealisedFxGroupAccount($memberId, $company->id, $moved < 0);
            if ($target === null) {
                $blockers[] = new Violation('net_investment_account_unmapped', "Company {$company->name}: the unrealised FX account in its settings has no group account, so the exchange difference on its net-investment loan cannot move to the translation reserve.");
            } else {
                $ni[] = ['account_id' => $target, 'net' => -$moved];
            }
        }

        return [$ic, $ni];
    }

    /** The group account of the company's unrealised FX account (its loss account for a loss, when it has one). */
    private function unrealisedFxGroupAccount(string $memberId, string $companyId, bool $loss): ?string
    {
        $raw = CompanySettings::query()->where('company_id', $companyId)->first()?->getAttribute('posting_accounts');
        /** @var array<string, mixed> $settings */
        $settings = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
        $refs = $loss ? [$settings['fx_unrealised_loss'] ?? null, $settings['fx_unrealised'] ?? null] : [$settings['fx_unrealised'] ?? null];
        foreach ($refs as $ref) {
            if (! is_string($ref) || $ref === '') {
                continue;
            }
            $account = Account::query()->where('company_id', $companyId)->where(fn ($q) => $q->where('code', $ref)->when(
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $ref) === 1,
                fn ($q2) => $q2->orWhere('id', $ref),
            ))->first();
            if ($account !== null) {
                $group = ConsolidationAccountMap::query()->where('member_id', $memberId)->where('account_id', $account->id)->value('group_account_id');

                return is_string($group) ? $group : null;
            }
        }

        return null;
    }

    /** @param array<string, Violation> $missingRates */
    private function dayRate(string $rateSource, string $currency, string $pres, string $day, array &$missingRates): ?string
    {
        $q = $this->rates->find($rateSource, $currency, $pres, $day);
        if ($q === null) {
            $missingRates["{$currency}@{$day}"] = new Violation('fx_rate_missing', "No {$currency}/{$pres} rate on or before {$day}.");
        }

        return $q?->rate;
    }

    /**
     * Balance sheet balances at $to and profit and loss movement of the window, by group account (the member's own figures
     * in its own currency), closing journals left out. The accounts mapped to retained earnings are not rows: the member's
     * retained earnings is the balancing figure (see ConsolidationCalculator).
     *
     * @param  array<string, string>  $maps  member account id → group account id
     * @param  array<string, array{type: string, role: string|null}>  $defs
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function figures(string $companyId, array $maps, array $defs, ?string $reAccount, ?string $windowFrom, string $to): array
    {
        $net = [];
        $total = 0;
        foreach ($this->balances->totals($companyId, null, $to, true) as $row) {
            $net[$row->accountId] = ($net[$row->accountId] ?? 0) + $row->debit - $row->credit;
            $total += $row->debit - $row->credit;
        }
        if ($total !== 0) {
            throw DomainRuleViolation::single('trial_balance_unbalanced', "Company {$companyId} has an unbalanced trial balance at {$to}; it cannot be consolidated.");
        }
        $movement = [];
        if ($windowFrom !== null) {
            foreach ($this->balances->totals($companyId, $windowFrom, $to, true) as $row) {
                $movement[$row->accountId] = ($movement[$row->accountId] ?? 0) + $row->debit - $row->credit;
            }
        }
        $bs = [];
        $pl = [];
        foreach ($maps as $accountId => $groupId) {
            $def = $defs[$groupId] ?? null;
            if ($def === null) {
                continue;
            }
            if (GroupRoles::isProfitAndLoss($def['type'])) {
                $pl[$groupId] = ($pl[$groupId] ?? 0) + ($movement[$accountId] ?? 0);
            } elseif ($groupId !== $reAccount) {
                $bs[$groupId] = ($bs[$groupId] ?? 0) + ($net[$accountId] ?? 0);
            }
        }

        return [array_filter($bs, fn (int $v): bool => $v !== 0), array_filter($pl, fn (int $v): bool => $v !== 0)];
    }

    /**
     * The opening the accountant entered for a subsidiary held before the first run: equity at historical rates and the
     * reserve as entered, against the books' own equity at its date. It must be dated the day before the run starts, and
     * cover exactly the equity accounts the books held then (an account with a balance and no amount, or the reverse, blocks).
     *
     * @param  array<string, string>  $maps
     * @param  array<string, array{type: string, role: string|null}>  $defs
     * @param  list<Violation>  $blockers
     * @return array{local: array<string, int>, translated: array<string, int>, tr: int}|null
     */
    private function entered(ConsolidationMemberOpening $opening, string $memberId, Company $company, array $maps, array $defs, ?string $reAccount, string $from, array &$blockers): ?array
    {
        $asOf = $opening->as_of->format('Y-m-d');
        if ($asOf !== Dates::before($from)) {
            $blockers[] = new Violation('consolidation_opening_date', "The opening translated equity of {$company->name} is as of {$asOf}: the first run starts on ".Dates::after($asOf).'.');

            return null;
        }
        $local = array_filter($this->equityAt($opening->group_id, $memberId, $company->id, $maps, $defs, $reAccount, $asOf, $blockers), fn (int $v): bool => $v !== 0);
        $translated = [];
        foreach ($opening->equity as $acc => $v) {
            $translated[(string) $acc] = (int) $v;
        }
        $missing = array_keys(array_diff_key($local, $translated));
        $extra = array_keys(array_diff_key($translated, $local));
        if ($missing !== [] || $extra !== []) {
            $codes = fn (array $ids): string => ConsolidationGroupAccount::query()->whereIn('id', $ids)->orderBy('code')->pluck('code')->implode(', ');
            $blockers[] = new Violation('consolidation_opening_incomplete', "The opening translated equity of {$company->name} does not match its books at {$asOf}: "
                .($missing !== [] ? 'no amount for '.$codes($missing).' (which has a balance)' : '').($missing !== [] && $extra !== [] ? '; ' : '')
                .($extra !== [] ? 'an amount for '.$codes($extra).' (which has none)' : '').'.');

            return null;
        }

        return ['local' => $local, 'translated' => $translated, 'tr' => $opening->translation_reserve];
    }

    /**
     * A member's own equity per group account at the close of $date, closing journals left out; retained earnings is the
     * balancing figure, so it holds every result up to then. An unmapped account with a balance then blocks.
     *
     * @param  array<string, string>  $maps
     * @param  array<string, array{type: string, role: string|null}>  $defs
     * @param  list<Violation>  $blockers
     * @return array<string, int>
     */
    private function equityAt(string $groupId, string $memberId, string $companyId, array $maps, array $defs, ?string $reAccount, string $date, array &$blockers): array
    {
        foreach (($this->unmapped)($groupId, null, $date) as $u) {
            if ($u->memberId === $memberId) {
                $blockers[] = new Violation('unmapped_account', "Account {$u->code} {$u->name} of company {$u->companyId} had a balance on {$date} and has no group account.");
            }
        }
        [$bs] = $this->figures($companyId, $maps, $defs, $reAccount, null, $date);
        $local = [];
        if ($reAccount !== null) {
            $local[$reAccount] = -array_sum($bs);
        }
        foreach ($bs as $acc => $v) {
            if (($defs[$acc]['type'] ?? '') === 'equity') {
                $local[$acc] = $v;
            }
        }

        return $local;
    }

    /**
     * A foreign subsidiary's equity at the close of its acquisition day, local and at that day's rate: its historical
     * starting point (D16.4). Retained earnings at acquisition is the balancing figure, pre-acquisition results included.
     *
     * @param  array<string, string>  $maps
     * @param  array<string, array{type: string, role: string|null}>  $defs
     * @param  array<string, Violation>  $missingRates
     * @param  list<Violation>  $blockers
     * @return array{local: array<string, int>, translated: array<string, int>, tr: int}|null
     */
    private function baseline(ConsolidationGroup $group, string $memberId, string $companyId, array $maps, array $defs, ?string $reAccount, string $date, string $currency, int $scale, int $groupScale, array &$missingRates, array &$blockers): ?array
    {
        $quote = $this->rates->find($group->parent_company_id, $currency, $group->presentation_currency, $date);
        if ($quote === null) {
            $missingRates["{$currency}@{$date}"] = new Violation('fx_rate_missing', "No {$currency}/{$group->presentation_currency} rate on or before {$date}.");

            return null;
        }
        $local = $this->equityAt($group->id, $memberId, $companyId, $maps, $defs, $reAccount, $date, $blockers);
        $translated = [];
        foreach ($local as $acc => $v) {
            $translated[$acc] = Rounding::translate($v, $quote->rate, $scale, $groupScale);
        }

        return ['local' => $local, 'translated' => $translated, 'tr' => 0];
    }

    /**
     * @param  array<mixed>  $rows
     * @return array<string, int>
     */
    private function ints(array $rows): array
    {
        $out = [];
        foreach ($rows as $k => $v) {
            $out[(string) $k] = is_numeric($v) ? (int) $v : 0;
        }

        return $out;
    }
}
