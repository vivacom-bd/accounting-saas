<?php

declare(strict_types=1);

use App\Domain\Ledger\DraftLine;
use App\Queries\Ledger\FindLedgerDrift;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\LedgerFixture;

/*
 * Nightly balance drift detector (13 §9, plan 4.2): `ledger:check-drift` queues one check per live tenant; each recomputes balances from
 * posted lines, compares them with account_period_balances, and records what differs, once.
 */

/** Edits the stored balances as a table owner would (the app role cannot), inside one tenant. @param Closure(Illuminate\Database\Connection): void $edit */
function tamperBalances(string $tenantId, Closure $edit): void
{
    $owner = DB::connection('pgsql_owner');
    $owner->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
    try {
        $edit($owner);
    } finally {
        $owner->statement("SELECT set_config('app.tenant_id', '', false)");
    }
}

/** Runs the scheduled command the way the scheduler does: with no tenant context. */
function drift(): void
{
    app(TenantContext::class)->clear();
    test()->artisan('ledger:check-drift')->assertExitCode(0);
}

function findings(LedgerFixture $f, ?string $kind = null): int
{
    app(TenantContext::class)->set($f->tenantId);

    return DB::table('ledger_drift_findings')->when($kind, fn ($q) => $q->where('kind', $kind))->count();
}

beforeEach(function (): void {
    $this->f = LedgerFixture::create();
    cashSale($this->f, 10000, '2026-02-10');
    postJournal($this->f, draftJournal($this->f, [new DraftLine($this->f->account('5000'), debit: 4000), new DraftLine($this->f->account('1000'), credit: 4000)], '2026-03-10'));
    $this->feb = $this->f->period('2026-02-10')->id;
    app(TenantContext::class)->clear();
});

it('finds nothing on a clean ledger and tells nobody', function (): void {
    drift();

    expect(findings($this->f))->toBe(0)->and(DB::table('user_notifications')->count())->toBe(0);
});

it('finds a tampered stored balance once, tells the owners, and finds nothing new on a rerun of the same state', function (): void {
    $cash = $this->f->account('1000');
    tamperBalances($this->f->tenantId, fn ($db) => $db->table('account_period_balances')->where('account_id', $cash)->where('fiscal_period_id', $this->feb)->update(['debit' => DB::raw('debit + 25')]));
    Log::spy();

    drift();
    app(TenantContext::class)->set($this->f->tenantId);
    $row = DB::table('ledger_drift_findings')->where('kind', 'balance')->sole();
    expect($row->account_id)->toBe($cash)->and($row->fiscal_period_id)->toBe($this->feb)->and($row->company_id)->toBe($this->f->companyId)
        ->and([(int) $row->expected_debit, (int) $row->stored_debit, (int) $row->expected_credit, (int) $row->stored_credit])->toBe([10000, 10025, 0, 0])
        ->and(findings($this->f, 'trial_balance'))->toBe(1)
        ->and(findings($this->f))->toBe(2)
        ->and(DB::table('user_notifications')->where('user_id', $this->f->userId)->where('kind', 'ledger_drift')->count())->toBe(1);
    Log::shouldHaveReceived('error')->twice();

    // The same state again: nothing is recorded or sent twice.
    app(TenantContext::class)->clear();
    drift();
    expect(findings($this->f))->toBe(2)->and(DB::table('user_notifications')->count())->toBe(1);

    // The ledger itself was never written to: the audit chain and the posted lines are as they were.
    expect((int) DB::table('journal_lines')->sum('debit'))->toBe(14000);
});

it('records a mismatch that changes as a new finding, and a missing balance row as a balance of zero', function (): void {
    $cash = $this->f->account('1000');
    $feb = $this->feb;
    tamperBalances($this->f->tenantId, fn ($db) => $db->table('account_period_balances')->where('account_id', $cash)->where('fiscal_period_id', $feb)->update(['debit' => DB::raw('debit + 25')]));
    drift();
    tamperBalances($this->f->tenantId, fn ($db) => $db->table('account_period_balances')->where('account_id', $cash)->where('fiscal_period_id', $feb)->update(['debit' => DB::raw('debit + 5')]));
    drift();
    expect(findings($this->f, 'balance'))->toBe(2);

    tamperBalances($this->f->tenantId, fn ($db) => $db->table('account_period_balances')->where('account_id', $this->f->account('5000'))->delete());
    drift();
    app(TenantContext::class)->set($this->f->tenantId);
    $gone = DB::table('ledger_drift_findings')->where('kind', 'balance')->where('account_id', $this->f->account('5000'))->sole();
    expect([(int) $gone->expected_debit, (int) $gone->stored_debit])->toBe([4000, 0]);
});

it('finds a posted journal whose header total no longer matches its lines', function (): void {
    app(TenantContext::class)->set($this->f->tenantId);
    $journal = DB::table('journals')->where('status', 'posted')->orderBy('number')->first();
    DB::connection('pgsql_owner')->statement("SELECT set_config('app.tenant_id', ?, false)", [$this->f->tenantId]);
    DB::connection('pgsql_owner')->statement('ALTER TABLE journals DISABLE TRIGGER USER');
    try {
        DB::connection('pgsql_owner')->table('journals')->where('id', $journal->id)->update(['total_debit' => DB::raw('total_debit + 1')]);
    } finally {
        DB::connection('pgsql_owner')->statement('ALTER TABLE journals ENABLE TRIGGER USER');
        DB::connection('pgsql_owner')->statement("SELECT set_config('app.tenant_id', '', false)");
    }

    drift();
    app(TenantContext::class)->set($this->f->tenantId);
    $row = DB::table('ledger_drift_findings')->where('kind', 'journal')->sole();
    expect($row->journal_id)->toBe($journal->id)->and((int) $row->stored_debit)->toBe((int) $journal->total_debit + 1)->and((int) $row->expected_debit)->toBe((int) $journal->total_debit);
});

it('keeps tenants apart: another tenant\'s clean books neither show nor hide this one\'s drift', function (): void {
    $other = LedgerFixture::create(slug: 'beta');
    cashSale($other, 500, '2026-02-10');
    app(TenantContext::class)->clear();
    $cash = $this->f->account('1000');
    tamperBalances($this->f->tenantId, fn ($db) => $db->table('account_period_balances')->where('account_id', $cash)->where('fiscal_period_id', $this->feb)->update(['credit' => DB::raw('credit + 1')]));

    drift();

    expect(findings($this->f, 'balance'))->toBe(1)->and(findings($other))->toBe(0);
    app(TenantContext::class)->clear();
});

it('reads through the query without writing anything', function (): void {
    app(TenantContext::class)->set($this->f->tenantId);

    expect(app(FindLedgerDrift::class)())->toBe([])->and(findings($this->f))->toBe(0);
});
