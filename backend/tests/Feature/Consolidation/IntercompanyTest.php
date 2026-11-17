<?php

declare(strict_types=1);

use App\Actions\Consolidation\ManageConsolidationGroup;
use App\Actions\Consolidation\ManageConsolidationRun;
use App\Actions\Consolidation\ManageIntercompany;
use App\Actions\Fx\Inputs\RunFxRevaluationInput;
use App\Actions\Fx\RunFxRevaluation;
use App\Actions\Ledger\Inputs\RecordJournalInput;
use App\Actions\Ledger\RecordJournal;
use App\Domain\Ledger\DraftLine;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalType;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Consolidation\ConsolidationEntry;
use App\Models\Consolidation\ConsolidationIcMatch;
use App\Models\Consolidation\ConsolidationRunLine;
use App\Models\Fiscal\FiscalPeriod;
use App\Models\Platform\User;
use App\Support\Actor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConsolidationFixture;

/*
 * Intercompany matching and eliminations through the run (D16.7, plan 3c.29c): the flags on the group's mapping, the
 * matching report frozen with the run, eliminations drafted by the run and posted when a second person approves it, the
 * tolerance gate, and the net-investment loan.
 */

beforeEach(function (): void {
    $this->grant('reports.view');
});

/** Consolidated worksheet by group account code. */
function icSheet(ConsolidationFixture $c, string $runId): array
{
    $codes = array_flip($c->groupAccounts);
    $out = [];
    foreach (ConsolidationRunLine::query()->where('run_id', $runId)->where('kind', 'consolidated')->get() as $l) {
        $out[(string) $codes[$l->group_account_id]] = $l->amount;
    }
    ksort($out);

    return $out;
}

/** The elimination and net-investment entries of a run: memo and [group account code, net] per line, by memo. */
function icLines(ConsolidationFixture $c, string $runId): array
{
    $codes = array_flip($c->groupAccounts);
    $out = [];
    foreach (ConsolidationEntry::query()->where('run_id', $runId)->whereIn('kind', ['elimination', 'net_investment'])->orderBy('memo')->get() as $e) {
        $out[] = [$e->memo, $e->lines()->orderBy('line_no')->get()->map(fn ($l) => [(string) $codes[$l->group_account_id], $l->debit - $l->credit])->all()];
    }

    return $out;
}

/** Flags accounts of a company intercompany: [code, class, counterparty key|null, net investment]. */
function flag(ConsolidationFixture $c, string $key, array $rows, array $contacts = []): void
{
    app(ManageIntercompany::class)->set($c->actor(), $c->members[$key]->id, array_map(fn (array $r): array => [
        'account_id' => $c->companies[$key]['accounts'][$r[0]], 'ic_class' => $r[1],
        'counterparty_member_id' => isset($r[2]) ? $c->members[$r[2]]->id : null, 'net_investment' => $r[3] ?? false,
    ], $rows), $contacts);
}

/** A USD parent P and a USD subsidiary S held since before 2026, each with capital, and P's sale of 1,000.00 to S. */
function usdPair(int $sBooks = 100000): array
{
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $c->subsidiary('S', '2025-12-31', 10000);
    $c->mapAll('P');
    $c->mapAll('S');
    $checker = $c->person();
    $c->post('P', '2026-01-02', [['1000', 5000000, 0], ['3000', 0, 5000000]]);
    $c->post('S', '2026-01-02', [['1000', 1000000, 0], ['3000', 0, 1000000]]);
    $c->post('P', '2026-01-10', [['1200', 100000, 0], ['4100', 0, 100000]]);
    $c->post('S', '2026-01-12', [['5100', $sBooks, 0], ['2100', 0, $sBooks]]);
    flag($c, 'P', [['1200', 'balance', 'S'], ['4100', 'income_expense', 'S']]);
    flag($c, 'S', [['2100', 'balance', 'P'], ['5100', 'income_expense', 'P']]);

    return [$c, $checker];
}

function expectViolation(Closure $work, string $code): void
{
    try {
        $work();
    } catch (DomainRuleViolation $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    throw new RuntimeException("Expected {$code}");
}

it('eliminates matched intragroup balances and sales, drafted by the run and posted by a second person, books untouched', function (): void {
    [$c, $checker] = usdPair();
    $before = $c->booksFingerprint();
    $runs = app(ManageConsolidationRun::class);

    $run = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31');

    $matches = ConsolidationIcMatch::query()->where('run_id', $run->id)->orderBy('ic_class')->get();
    expect($matches->map(fn ($m) => [$m->ic_class, $m->side_a + $m->side_b, $m->matched, $m->cause, $m->exceeds_tolerance])->all())->toBe([
        ['balance', 0, 100000, null, false], ['income_expense', 0, 100000, null, false],
    ])
        // Nothing intragroup is left: cash 60,000.00 and capital only.
        ->and(icSheet($c, $run->id))->toBe(['1000' => 6000000, '3000' => -6000000])
        ->and(ConsolidationEntry::query()->where('run_id', $run->id)->where('kind', 'elimination')->where('status', 'draft')->count())->toBe(2);

    $runs->approve($checker, $run->id);

    $posted = ConsolidationEntry::query()->where('run_id', $run->id)->orderBy('number')->get();
    expect($posted->pluck('status')->unique()->all())->toBe(['posted'])
        ->and($posted->pluck('number')->all())->toBe(['CE-2026-00001', 'CE-2026-00002'])
        ->and($posted->every(fn ($e) => $e->approved_by === $checker->id && $e->total_debit === 100000))->toBeTrue()
        ->and($c->booksFingerprint())->toBe($before)
        ->and(fn () => DB::table('consolidation_entry_lines')->where('entry_id', $posted[0]->id)->update(['debit' => 1]))->toThrow(QueryException::class, 'never changed');
});

it('works eliminations out again each run instead of carrying the last run\'s, and a reversed run leaves no trace', function (): void {
    [$c, $checker] = usdPair();
    $runs = app(ManageConsolidationRun::class);
    $jan = $runs->approve($checker, $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31')->id);

    // February: no new trade; the receivable and payable are still open, so they are eliminated again, once.
    $feb = $runs->create($c->actor(), $c->group->id, '2026-02-01', '2026-02-28');
    expect(icSheet($c, $feb->id))->toBe(['1000' => 6000000, '3000' => -6000000])
        ->and(ConsolidationEntry::query()->where('run_id', $feb->id)->count())->toBe(1);   // balances only; no intragroup sales in February
    $runs->void($c->actor(), $feb->id, 'redo');

    $runs->reverse($checker, $jan->id, 'Rework');
    expect(ConsolidationEntry::query()->where('run_id', $jan->id)->where('kind', 'reversal')->count())->toBe(2);

    $again = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31');
    expect(icSheet($c, $again->id))->toBe(['1000' => 6000000, '3000' => -6000000]);
});

it('blocks approval on a difference above tolerance until a second person accepts it, and eliminates both sides in full with the difference to G6910', function (): void {
    [$c, $checker] = usdPair(98000);   // S booked 980.00 of P's 1,000.00
    app(ManageConsolidationGroup::class)->update($c->actor(), $c->group->id, $c->group->name, 1000, $c->group->refresh()->lock_version);
    $runs = app(ManageConsolidationRun::class);

    $run = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31');
    $matches = ConsolidationIcMatch::query()->where('run_id', $run->id)->orderBy('ic_class')->get();

    expect($matches->map(fn ($m) => [$m->ic_class, $m->difference, $m->matched, $m->cause, $m->exceeds_tolerance])->all())->toBe([
        ['balance', 2000, 98000, 'timing', true], ['income_expense', -2000, 98000, 'timing', true],
    ])
        // Nothing intragroup is left. The receivable's 20.00 is a loss to the group and the sales' 20.00 a gain, both in
        // G6910, so the account nets to nil and the group's profit is nil (the members' 20.00 was never earned outside).
        ->and(icSheet($c, $run->id))->toBe(['1000' => 6000000, '3000' => -6000000])
        ->and(icLines($c, $run->id))->toBe([
            ['Elimination of intragroup income and expense', [['4100', 100000], ['5100', -98000], ['G6910', -2000]]],
            ['Elimination of intragroup receivables and payables', [['1200', -100000], ['2100', 98000], ['G6910', 2000]]],
        ])
        ->and($run->summary['profit'])->toBe(0);

    expectViolation(fn () => $runs->approve($checker, $run->id), 'ic_difference_unaccepted');
    expectViolation(fn () => $runs->acceptDifference($c->actor(), $run->id, $matches[0]->id, 'Invoice in transit'), 'self_approval_forbidden');
    expectViolation(fn () => $runs->acceptDifference($checker, $run->id, $matches[0]->id, '  '), 'reason_required');

    foreach ($matches as $m) {
        $runs->acceptDifference($checker, $run->id, $m->id, 'Invoice of 20.00 in transit, booked by S in February');
    }
    expectViolation(fn () => $runs->acceptDifference($checker, $run->id, $matches[0]->id, 'again'), 'ic_difference_accepted');
    expect(fn () => DB::table('consolidation_ic_matches')->where('id', $matches[0]->id)->update(['reason' => 'changed']))->toThrow(QueryException::class, 'already accepted')
        ->and(fn () => DB::table('consolidation_ic_matches')->where('id', $matches[0]->id)->delete())->toThrow(QueryException::class, 'never deleted')
        ->and(DB::table('audit_events')->where('action', 'consolidation_ic_match.accepted')->count())->toBe(2);

    expect($runs->approve($checker, $run->id)->status)->toBe('approved');
});

it('refuses, in the database, a change to the report other than an acceptance, and an acceptance by the maker', function (): void {
    [$c] = usdPair(98000);
    $run = app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31');
    $id = ConsolidationIcMatch::query()->where('run_id', $run->id)->value('id');

    expect(fn () => DB::table('consolidation_ic_matches')->where('id', $id)->update(['difference' => 0, 'side_a' => 98000]))->toThrow(QueryException::class, 'only the acceptance')
        ->and(fn () => DB::table('consolidation_ic_matches')->where('id', $id)->update(['accepted_by' => $c->actor()->id, 'accepted_at' => now(), 'reason' => 'mine']))->toThrow(QueryException::class, 'second person');
});

it('finds the counterparty from the contact on each line, leaving external customers alone', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $c->subsidiary('S', '2025-12-31', 10000);
    $c->mapAll('P');
    $c->mapAll('S');
    $p = $c->companies['P']['id'];
    $sContact = (string) Str::uuid7();
    $external = (string) Str::uuid7();
    foreach ([[$sContact, 'S1', 'Company S'], [$external, 'X1', 'Outsider']] as [$id, $code, $name]) {
        DB::table('contacts')->insert(['id' => $id, 'tenant_id' => $c->f->tenantId, 'company_id' => $p, 'kind' => 'customer', 'code' => $code, 'name' => $name, 'default_currency' => 'USD']);
    }
    $acc = $c->companies['P']['accounts'];
    app(RecordJournal::class)->handle(Actor::system($c->f->tenantId, $p), new RecordJournalInput(new JournalDraft($p, JournalType::Manual, new DateTimeImmutable('2026-01-10'), [
        new DraftLine($acc['1100'], debit: 50000, contactId: $sContact), new DraftLine($acc['1100'], debit: 30000, contactId: $external),
        new DraftLine($acc['4000'], credit: 80000),
    ], 'T', 'sales')));
    $c->post('S', '2026-01-10', [['5000', 50000, 0], ['2000', 0, 50000]]);
    flag($c, 'P', [['1100', 'balance']], [['contact_id' => $sContact, 'counterparty_member_id' => $c->members['S']->id]]);
    flag($c, 'S', [['2000', 'balance', 'P']]);

    $run = app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31');

    expect(ConsolidationIcMatch::query()->where('run_id', $run->id)->get()->map(fn ($m) => [$m->ic_class, $m->difference, $m->matched])->all())->toBe([['balance', 0, 50000]])
        ->and(icSheet($c, $run->id))->toMatchArray(['1100' => 30000])
        ->and(icSheet($c, $run->id))->not->toHaveKey('2000');
});

it('refuses intercompany flags that do not fit: class and account type, a self counterparty, a non-monetary loan, a net-investment balance', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $c->subsidiary('S', '2025-12-31', 10000);
    $c->mapAll('P');
    $c->mapAll('S');

    expectViolation(fn () => flag($c, 'P', [['1200', 'income_expense', 'S']]), 'ic_class_type_mismatch');
    expectViolation(fn () => flag($c, 'P', [['1200', 'balance', 'P']]), 'ic_counterparty_invalid');
    expectViolation(fn () => flag($c, 'P', [['1200', 'balance', 'S', true]]), 'net_investment_not_loan');
    // 1800 sits in the 'Investments' subtype, which is non-monetary by default: it would never be revalued.
    expectViolation(fn () => flag($c, 'P', [['1800', 'loan', 'S']]), 'ic_loan_not_monetary');
    DB::table('accounts')->where('id', $c->companies['P']['accounts']['1800'])->update(['reporting_tags' => json_encode(['fx:monetary'])]);
    flag($c, 'P', [['1800', 'loan', 'S', true]]);
    flag($c, 'S', [['2200', 'loan', 'P', true]]);   // a non-current liability is monetary

    expect(DB::table('consolidation_account_maps')->whereNotNull('ic_class')->count())->toBe(2);
});

it('revalues a EUR subsidiary\'s USD loan from its parent in its own books, matches it after translation, and moves every exchange difference of a net-investment loan, realised and unrealised, to the translation reserve', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'EUR');
    $c->group();
    $c->subsidiary('S', '2025-12-31', 10000);
    $c->mapAll('P');
    $c->mapAll('S');
    $checker = $c->person();
    $c->rate('P', 'EUR', 'USD', '2025-12-31', '1.10');
    $c->rate('P', 'EUR', 'USD', '2026-01-31', '1.05');
    $c->rate('S', 'USD', 'EUR', '2026-01-05', '0.90');
    $c->rate('S', 'USD', 'EUR', '2026-01-20', '0.95');
    $s = $c->companies['S'];
    DB::table('company_settings')->insert(['tenant_id' => $c->f->tenantId, 'company_id' => $s['id'], 'posting_accounts' => json_encode(['fx_unrealised' => '5000', 'fx_realised' => '5000'])]);

    // P lends USD 1,000.00; S books it at 0.90 = EUR 900.00 in its non-current loan account.
    $c->post('P', '2026-01-05', [['1200', 100000, 0], ['1000', 0, 100000]]);
    $c->post('P', '2026-01-02', [['1000', 500000, 0], ['3000', 0, 500000]]);
    app(RecordJournal::class)->handle(Actor::system($c->f->tenantId, $s['id']), new RecordJournalInput(new JournalDraft($s['id'], JournalType::Manual, new DateTimeImmutable('2026-01-05'), [
        new DraftLine($s['accounts']['1000'], debit: 90000), new DraftLine($s['accounts']['2200'], 0, 90000, 'USD', '0.90000000', -100000),
    ], 'T', 'loan')));
    // On 20 Jan S repays USD 400.00 at 0.95 = EUR 380.00, carried at EUR 360.00: a realised loss of EUR 20.00. P gets USD 400.00.
    app(RecordJournal::class)->handle(Actor::system($c->f->tenantId, $s['id']), new RecordJournalInput(new JournalDraft($s['id'], JournalType::Manual, new DateTimeImmutable('2026-01-20'), [
        new DraftLine($s['accounts']['2200'], 36000, 0, 'USD', '0.90000000', 40000), new DraftLine($s['accounts']['5000'], debit: 2000),
        new DraftLine($s['accounts']['1000'], credit: 38000),
    ], 'T', 'repayment')));
    $c->post('P', '2026-01-20', [['1000', 40000, 0], ['1200', 0, 40000]]);
    // Month end in S's books: USD 600.00 at 0.95 = EUR 570.00 against EUR 540.00 carried, an unrealised loss of EUR 30.00
    // (IAS 21.23, the loan is monetary).
    $period = FiscalPeriod::query()->where('company_id', $s['id'])->where('starts_on', '2026-01-01')->firstOrFail();
    $reval = app(RunFxRevaluation::class)->handle(Actor::system($c->f->tenantId, $s['id']), new RunFxRevaluationInput($s['id'], $period->id, null));
    expect($reval->total_unrealised)->toBe(-3000);

    flag($c, 'P', [['1200', 'loan', 'S']]);
    flag($c, 'S', [['2200', 'loan', 'P', true]]);
    $runs = app(ManageConsolidationRun::class);
    $run = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31');

    // S's EUR 570.00 at closing 1.05 = USD 598.50 against P's USD 600.00: 1.50 of difference from rates, both sides out in full.
    $m = ConsolidationIcMatch::query()->where('run_id', $run->id)->firstOrFail();
    expect([$m->ic_class, $m->difference, $m->matched, $m->cause])->toBe(['loan', 150, 59850, 'fx']);

    // All the exchange differences on the loan, the realised EUR 20.00 on the repayment and the unrealised EUR 30.00, at the
    // January daily mean (30 days at 1.10, 1 at 1.05 = 1.09838710): USD 54.92, out of profit or loss and into the reserve.
    expect(icLines($c, $run->id))->toBe([
        ['Elimination of intragroup loans', [['1200', -60000], ['2200', 59850], ['G6910', 150]]],
        ['Exchange differences on a loan forming part of the net investment, to the translation reserve (IAS 21.32)', [['5000', -5492], ['G3200', 5492]]],
    ])
        ->and(icSheet($c, $run->id))->not->toHaveKey('5000');
    $ni = ConsolidationEntry::query()->where('run_id', $run->id)->where('kind', 'net_investment')->firstOrFail();

    $runs->acceptDifference($checker, $run->id, $m->id, 'Rate difference on the loan');
    expect($runs->approve($checker, $run->id)->status)->toBe('approved')
        ->and($ni->refresh()->status)->toBe('posted');
});

it('serves the intercompany flags, the matching report and acceptance over the API, acceptance left to a second person', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $c->subsidiary('S', '2025-12-31', 10000);
    $c->mapAll('P');
    $c->mapAll('S');
    $checker = $c->person();
    $c->post('P', '2026-01-10', [['1200', 100000, 0], ['4100', 0, 100000]]);
    $c->post('S', '2026-01-12', [['5100', 98000, 0], ['2100', 0, 98000]]);
    $owner = User::query()->findOrFail($c->f->userId);
    $p = $c->members['P']->id;
    $s = $c->members['S']->id;

    $mapping = $this->actingAs($owner)->putJson("/api/v1/consolidation/members/{$p}/intercompany", [
        'accounts' => [['account_id' => $c->companies['P']['accounts']['1200'], 'ic_class' => 'balance', 'counterparty_member_id' => $s]], 'contacts' => [],
    ])->assertOk()->json('data');
    expect(collect($mapping)->firstWhere('account_id', $c->companies['P']['accounts']['1200']))->toMatchArray(['ic_class' => 'balance', 'counterparty_member_id' => $s, 'net_investment' => false]);
    $this->putJson("/api/v1/consolidation/members/{$s}/intercompany", [
        'accounts' => [['account_id' => $c->companies['S']['accounts']['2100'], 'ic_class' => 'balance', 'counterparty_member_id' => $p]], 'contacts' => [],
    ])->assertOk();
    $this->putJson("/api/v1/consolidation/members/{$s}/intercompany", ['accounts' => [['account_id' => $c->companies['S']['accounts']['2100'], 'ic_class' => 'sideways']], 'contacts' => []])
        ->assertStatus(422);
    $this->getJson("/api/v1/consolidation/members/{$p}/intercompany-contacts")->assertOk()->assertJsonCount(0, 'data');

    $run = $this->postJson("/api/v1/consolidation/groups/{$c->group->id}/runs", ['from' => '2026-01-01', 'to' => '2026-01-31'])->assertCreated()->json('data.id');
    $report = $this->getJson("/api/v1/consolidation/runs/{$run}/intercompany")->assertOk()
        ->assertJsonPath('data.awaiting_acceptance', 1)->assertJsonPath('data.ic_tolerance.amount', 0)
        ->assertJsonPath('data.matches.0.difference.amount', 2000)->assertJsonPath('data.matches.0.matched.amount', 98000)
        ->assertJsonPath('data.matches.0.cause', 'timing')->json('data.matches.0');

    $this->postJson("/api/v1/consolidation/runs/{$run}/approve")->assertForbidden();
    $this->postJson("/api/v1/consolidation/runs/{$run}/intercompany/{$report['id']}/accept", ['reason' => 'mine'])->assertForbidden();
    $this->actingAs(User::query()->findOrFail($checker->id))->postJson("/api/v1/consolidation/runs/{$run}/approve")
        ->assertStatus(422)->assertJsonPath('violations.0.code', 'ic_difference_unaccepted');
    $this->postJson("/api/v1/consolidation/runs/{$run}/intercompany/{$report['id']}/accept", ['reason' => 'Invoice in transit'])
        ->assertOk()->assertJsonPath('data.awaiting_acceptance', 0)->assertJsonPath('data.matches.0.accepted_by', $checker->id)
        ->assertJsonPath('data.matches.0.reason', 'Invoice in transit');
    $this->postJson("/api/v1/consolidation/runs/{$run}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
});

it('takes only the movement of a receivable difference to profit or loss across three runs, the rest in retained earnings, and a re-run agrees', function (): void {
    [$c, $checker] = usdPair(98000);   // S booked 980.00 of P's 1,000.00 in January, the other 20.00 in March
    app(ManageConsolidationGroup::class)->update($c->actor(), $c->group->id, $c->group->name, 5000, $c->group->refresh()->lock_version);
    $runs = app(ManageConsolidationRun::class);
    $month = function (string $from, string $to) use ($c, $runs, $checker) {
        $run = $runs->approve($checker, $runs->create($c->actor(), $c->group->id, $from, $to)->id);

        return [$run, icLines($c, $run->id), ConsolidationIcMatch::query()->where('run_id', $run->id)->where('ic_class', 'balance')->firstOrFail()];
    };

    // January: the receivable's 20.00 is a loss in G6910, the sales' 20.00 a gain there.
    [$jan, $lines, $bal] = $month('2026-01-01', '2026-01-31');
    expect($lines)->toBe([
        ['Elimination of intragroup income and expense', [['4100', 100000], ['5100', -98000], ['G6910', -2000]]],
        ['Elimination of intragroup receivables and payables', [['1200', -100000], ['2100', 98000], ['G6910', 2000]]],
    ])->and([$bal->difference, $bal->recognised_before])->toBe([2000, 0])->and($jan->summary['profit'])->toBe(0);

    // February: nothing new. The 20.00 recognised in January sits in retained earnings; no profit or loss.
    [$feb, $lines, $bal] = $month('2026-02-01', '2026-02-28');
    expect($lines)->toBe([
        ['Elimination of intragroup receivables and payables', [['1200', -100000], ['2100', 98000], ['G3000', 2000]]],
    ])->and([$bal->difference, $bal->recognised_before])->toBe([2000, 2000])
        ->and($feb->summary['profit'])->toBe(0)
        ->and(icSheet($c, $feb->id))->toBe(['1000' => 6000000, '3000' => -6000000]);   // retained earnings nets to nil

    // March: S books the missing 20.00. The difference is gone, so G6910 takes back January's loss; S's late expense has no
    // counterpart this period and is a difference of its own. The group's profit stays nil, as it was all along.
    $c->post('S', '2026-03-05', [['5100', 2000, 0], ['2100', 0, 2000]]);
    [$mar, $lines, $bal] = $month('2026-03-01', '2026-03-31');
    $expected = [
        ['Elimination of intragroup income and expense', [['5100', -2000], ['G6910', 2000]]],
        ['Elimination of intragroup receivables and payables', [['1200', -100000], ['2100', 100000], ['G6910', -2000], ['G3000', 2000]]],
    ];
    expect($lines)->toBe($expected)->and([$bal->difference, $bal->recognised_before])->toBe([0, 2000])
        ->and($mar->summary['profit'])->toBe(0)
        ->and(icSheet($c, $mar->id))->toBe(['1000' => 6000000, '3000' => -6000000]);

    // Reversed and run again: the same entries, standing on February as before.
    $runs->reverse($checker, $mar->id, 'Rework');
    $again = $runs->create($c->actor(), $c->group->id, '2026-03-01', '2026-03-31');
    expect(icLines($c, $again->id))->toBe($expected)->and(icSheet($c, $again->id))->toBe(icSheet($c, $mar->id));
});

it('sends the FX part of a dividend received by a EUR subsidiary to the translation reserve, eliminating it with no difference', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'EUR');
    $c->group();
    $c->subsidiary('S', '2025-12-31', 10000);
    $c->mapAll('P');
    $c->mapAll('S');
    $checker = $c->person();
    $c->rate('P', 'EUR', 'USD', '2025-12-31', '1.10');
    $c->rate('P', 'EUR', 'USD', '2026-01-31', '1.05');
    // P pays USD 1,100.00 on 15 Jan; S receives EUR 1,000.00 (1.10 that day).
    $c->post('P', '2026-01-02', [['1000', 500000, 0], ['3000', 0, 500000]]);
    $c->post('P', '2026-01-15', [['3200', 110000, 0], ['1000', 0, 110000]]);
    $c->post('S', '2026-01-15', [['1000', 100000, 0], ['4200', 0, 100000]]);
    flag($c, 'P', [['3200', 'dividend', 'S']]);
    flag($c, 'S', [['4200', 'dividend', 'P']]);

    $run = app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31');

    // S's income at the January mean (1.09838710) is USD 1,098.39; at its day's rate USD 1,100.00. The 1.61 between is FX.
    $m = ConsolidationIcMatch::query()->where('run_id', $run->id)->firstOrFail();
    expect([$m->ic_class, $m->side_a + $m->side_b, $m->to_reserve, $m->difference, $m->exceeds_tolerance])->toBe(['dividend', 161, 161, 0, false])
        ->and(icLines($c, $run->id))->toBe([
            ['Elimination of intragroup dividends', [['3200', -110000], ['4200', 109839], ['G3200', 161]]],
        ])
        ->and(icSheet($c, $run->id))->not->toHaveKey('4200')->and(icSheet($c, $run->id))->not->toHaveKey('3200');
    expect(app(ManageConsolidationRun::class)->approve($checker, $run->id)->status)->toBe('approved');
});
