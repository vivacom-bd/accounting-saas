<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Consolidation\ManageConsolidationGroup;
use App\Actions\Fiscal\CreateFiscalYear;
use App\Actions\Fiscal\Inputs\CreateFiscalYearInput;
use App\Actions\Fx\Inputs\ExchangeRateInput;
use App\Actions\Fx\RecordExchangeRate;
use App\Actions\Ledger\Inputs\RecordJournalInput;
use App\Actions\Ledger\RecordJournal;
use App\Contracts\IdGenerator;
use App\Domain\Ledger\DraftLine;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalType;
use App\Models\Consolidation\ConsolidationGroup;
use App\Models\Consolidation\ConsolidationGroupAccount;
use App\Models\Consolidation\ConsolidationMember;
use App\Models\Ledger\Account;
use App\Support\Actor;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A tenant with several companies for the consolidation tests. Each company has the same small chart (codes below), a
 * fiscal year 2026 and a helper to post balanced journals in its own currency. The group chart uses the same codes, so
 * mapping is by code; roles come from the group's default accounts.
 */
final class ConsolidationFixture
{
    /** code → [name, type, normal, control, subtype] */
    public const array CHART = [
        '1000' => ['Cash', 'asset', 'debit', 'cash', 'Cash & Equivalents'],
        '1100' => ['Receivables', 'asset', 'debit', 'none', 'Current Assets'],
        '1200' => ['Intercompany receivable', 'asset', 'debit', 'none', 'Current Assets'],
        '1500' => ['Equipment', 'asset', 'debit', 'none', 'Property, Plant & Equipment'],
        '1800' => ['Investment in subsidiary', 'asset', 'debit', 'none', 'Investments'],
        '2000' => ['Payables', 'liability', 'credit', 'none', 'Current Liabilities'],
        '2100' => ['Intercompany payable', 'liability', 'credit', 'none', 'Current Liabilities'],
        '2200' => ['Long-term loan', 'liability', 'credit', 'none', 'Non-current Liabilities'],
        '3000' => ['Share capital', 'equity', 'credit', 'none', 'Contributed Capital'],
        '3100' => ['Retained earnings', 'equity', 'credit', 'retained_earnings', 'Retained Earnings'],
        '3200' => ['Dividends', 'equity', 'debit', 'none', 'Retained Earnings'],
        '4000' => ['Revenue', 'income', 'credit', 'none', 'Operating Revenue'],
        '4100' => ['Intercompany revenue', 'income', 'credit', 'none', 'Operating Revenue'],
        '4200' => ['Dividend income', 'income', 'credit', 'none', 'Other Income'],
        '5000' => ['Expenses', 'expense', 'debit', 'none', 'Operating Expense'],
        '5100' => ['Intercompany expense', 'expense', 'debit', 'none', 'Operating Expense'],
    ];

    /** @var array<string, array{id: string, accounts: array<string, string>, currency: string}> */
    public array $companies = [];

    public ConsolidationGroup $group;

    /** @var array<string, ConsolidationMember> company key → member */
    public array $members = [];

    /** @var array<string, string> group account code → id */
    public array $groupAccounts = [];

    public function __construct(public readonly LedgerFixture $f) {}

    /** The fixture's own company is "P", the parent. */
    public static function create(string $parentCurrency = 'USD'): self
    {
        $f = LedgerFixture::create($parentCurrency);
        $c = new self($f);
        $c->companies['P'] = ['id' => $f->companyId, 'accounts' => [], 'currency' => $parentCurrency];
        $c->chart('P', true);

        return $c;
    }

    public function company(string $key, string $currency): string
    {
        $id = app(IdGenerator::class)->next();
        DB::table('companies')->insert([
            'id' => $id, 'tenant_id' => $this->f->tenantId, 'name' => "Company {$key}", 'slug' => strtolower($key).'-co',
            'country' => 'US', 'base_currency' => $currency,
        ]);
        $this->companies[$key] = ['id' => $id, 'accounts' => [], 'currency' => $currency];
        $this->chart($key, false);
        app(CreateFiscalYear::class)->handle(Actor::system($this->f->tenantId, $id), new CreateFiscalYearInput($id, new DateTimeImmutable('2026-01-01'), 'FY2026'));

        return $id;
    }

    private function chart(string $key, bool $existing): void
    {
        $company = $this->companies[$key]['id'];
        if ($existing) {
            DB::table('accounts')->where('company_id', $company)->delete();
        }
        foreach (self::CHART as $code => [$name, $type, $normal, $control, $subtype]) {
            $id = app(IdGenerator::class)->next();
            DB::table('accounts')->insert([
                'id' => $id, 'tenant_id' => $this->f->tenantId, 'company_id' => $company, 'code' => $code, 'name' => $name,
                'account_type' => $type, 'account_subtype' => $subtype, 'normal_balance' => $normal, 'control_type' => $control,
                'currency_policy' => 'any', 'allow_manual_journals' => true,
            ]);
            $this->companies[$key]['accounts'][$code] = $id;
        }
    }

    /**
     * Posts a balanced journal in the company's own currency. Lines: [code, debit, credit].
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $lines
     */
    public function post(string $key, string $date, array $lines): void
    {
        $co = $this->companies[$key];
        $draft = array_map(fn (array $l) => new DraftLine($co['accounts'][$l[0]], debit: $l[1], credit: $l[2]), $lines);
        app(RecordJournal::class)->handle(
            Actor::system($this->f->tenantId, $co['id']),
            new RecordJournalInput(new JournalDraft($co['id'], JournalType::Manual, new DateTimeImmutable($date), $draft, 'T', 'test')),
        );
    }

    public function rate(string $key, string $from, string $to, string $date, string $rate): void
    {
        app(RecordExchangeRate::class)->handle(Actor::system($this->f->tenantId, $this->companies[$key]['id']), new ExchangeRateInput($this->companies[$key]['id'], $from, $to, $date, $rate));
    }

    public function actor(): Actor
    {
        return $this->f->actor;
    }

    /** Creates the group with parent P and the standard group chart (same codes as the company chart). */
    public function group(string $name = 'Group'): ConsolidationGroup
    {
        $action = app(ManageConsolidationGroup::class);
        $this->group = $action->create($this->actor(), $name, $this->companies['P']['currency'], $this->companies['P']['id']);
        $this->members['P'] = ConsolidationMember::query()->where('group_id', $this->group->id)->where('role', 'parent')->firstOrFail();
        foreach (ConsolidationGroupAccount::query()->where('group_id', $this->group->id)->get() as $a) {
            $this->groupAccounts[$a->code] = $a->id;
        }
        foreach (self::CHART as $code => [$name, $type, , , $subtype]) {
            $code = (string) $code;
            if ($code === '3100') {
                continue;   // retained earnings maps to the role account
            }
            $this->groupAccounts[$code] = $action->saveAccount($this->actor(), $this->group->id, null, $code, $name, $type, $subtype)->id;
        }

        return $this->group;
    }

    public function subsidiary(string $key, string $acquisitionDate, int $percentBp): ConsolidationMember
    {
        return $this->members[$key] = app(ManageConsolidationGroup::class)->addSubsidiary($this->actor(), $this->group->id, $this->companies[$key]['id'], $acquisitionDate, $percentBp);
    }

    /** Maps every account of the company to the group account of the same code (retained earnings to the role account). */
    public function mapAll(string $key): void
    {
        $pairs = [];
        foreach ($this->companies[$key]['accounts'] as $code => $accountId) {
            $code = (string) $code;
            $pairs[] = ['account_id' => $accountId, 'group_account_id' => $this->groupAccounts[$code === '3100' ? 'G3000' : $code]];
        }
        app(ManageConsolidationGroup::class)->mapAccounts($this->actor(), $this->members[$key]->id, $pairs);
    }

    public function account(string $key, string $code): Account
    {
        return Account::query()->findOrFail($this->companies[$key]['accounts'][$code]);
    }
}
