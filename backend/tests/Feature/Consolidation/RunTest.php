<?php

declare(strict_types=1);

use App\Actions\Consolidation\Inputs\EntryDraftInput;
use App\Actions\Consolidation\ManageConsolidationEntry;
use App\Actions\Consolidation\ManageConsolidationGroup;
use App\Actions\Consolidation\ManageConsolidationRun;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Consolidation\ConsolidationEntry;
use App\Models\Consolidation\ConsolidationRunLine;
use App\Models\Consolidation\ConsolidationRunMember;
use App\Models\Platform\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConsolidationFixture;

beforeEach(function (): void {
    $this->grant('reports.view');
});

/** A manual group entry (net per [code, net]), drafted by the owner and posted by a second person. */
function groupEntry(ConsolidationFixture $c, $checker, string $date, array $lines, ?string $member = null): ConsolidationEntry
{
    $entries = app(ManageConsolidationEntry::class);
    $draft = $entries->draftManual($c->actor(), new EntryDraftInput($c->group->id, 'manual', $date, array_map(
        fn (array $l): array => ['account_id' => $c->groupAccounts[$l[0]], 'member_id' => $member, 'net' => $l[1]], $lines,
    ), 'Acquisition', $member));

    return $entries->approve($checker, $draft->id);
}

/** One kind of worksheet line of a run, by group account code (and member, for local and translated). */
function sheet(ConsolidationFixture $c, string $runId, string $kind = 'consolidated', ?string $member = null): array
{
    $codes = array_flip($c->groupAccounts);
    $out = [];
    $q = ConsolidationRunLine::query()->where('run_id', $runId)->where('kind', $kind);
    if ($member !== null) {
        $q->where('member_id', $c->members[$member]->id);
    }
    foreach ($q->get() as $l) {
        $out[(string) $codes[$l->group_account_id]] = $l->amount;
    }
    ksort($out);

    return $out;
}

function sameCurrencyGroup(): array
{
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $c->subsidiary('S', '2026-01-03', 10000);
    $c->mapAll('P');
    $c->mapAll('S');
    $checker = $c->person();
    // P holds 100,000 of capital and buys all of S for 60,000. S was set up with 60,000 of capital.
    $c->post('P', '2026-01-02', [['1000', 100000, 0], ['3000', 0, 100000]]);
    $c->post('P', '2026-01-03', [['1800', 60000, 0], ['1000', 0, 60000]]);
    $c->post('S', '2026-01-02', [['1000', 60000, 0], ['3000', 0, 60000]]);
    groupEntry($c, $checker, '2026-01-03', [['3000', 60000], ['1800', -60000]], $c->members['S']->id);
    $c->post('S', '2026-02-10', [['1000', 20000, 0], ['4000', 0, 20000]]);
    $c->post('P', '2026-02-11', [['5000', 5000, 0], ['1000', 0, 5000]]);

    return [$c, $checker];
}

/**
 * A USD parent that bought all of a KES subsidiary on 31 March 2026 for 800,000 (USD minor units), when the subsidiary held
 * KES 1,000,000.00 of capital and nothing else. Rates (USD per KES), from the parent's rate table:
 * 31 Mar 0.0080, 31 May 0.0076, 30 Jun 0.0075.
 * The subsidiary in the quarter: revenue 300,000.00 on 15 Apr, expense 100,000.00 on 20 May, a dividend of 50,000.00 on 10 Jun.
 */
function kesGroup(): array
{
    $c = ConsolidationFixture::create('USD');
    $c->company('K', 'KES');
    $c->group();
    $c->subsidiary('K', '2026-03-31', 10000);
    $c->mapAll('P');
    $c->mapAll('K');
    $checker = $c->person();
    foreach (['2026-03-31' => '0.0080', '2026-05-31' => '0.0076', '2026-06-30' => '0.0075'] as $day => $rate) {
        $c->rate('P', 'KES', 'USD', $day, $rate);
    }
    $c->post('P', '2026-01-02', [['1000', 1000000, 0], ['3000', 0, 1000000]]);
    $c->post('P', '2026-03-31', [['1800', 800000, 0], ['1000', 0, 800000]]);
    $c->post('K', '2026-03-01', [['1000', 100000000, 0], ['3000', 0, 100000000]]);
    $c->post('K', '2026-04-15', [['1000', 30000000, 0], ['4000', 0, 30000000]]);
    $c->post('K', '2026-05-20', [['5000', 10000000, 0], ['1000', 0, 10000000]]);
    $c->post('K', '2026-06-10', [['3200', 5000000, 0], ['1000', 0, 5000000]]);
    // Acquisition at book value (eliminations and goodwill are later steps): Dr capital 800,000, Cr investment 800,000.
    groupEntry($c, $checker, '2026-03-31', [['3000', 800000], ['1800', -800000]], $c->members['K']->id);

    return [$c, $checker];
}

it('consolidates a parent and a 100% subsidiary in one currency: hand-computed worksheet, approved by a second person, books untouched', function (): void {
    [$c, $checker] = sameCurrencyGroup();
    $before = $c->booksFingerprint();

    $run = app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-01-01', '2026-02-28');

    // Parent: cash 35,000; investment 60,000; capital −100,000; expense 5,000. Sub (window from 4 Jan): cash 80,000;
    // capital −60,000; revenue −20,000. Group entry: Dr capital 60,000, Cr investment 60,000.
    // Consolidated: cash 115,000; investment 0; capital −100,000; revenue −20,000; expense 5,000.
    expect($run->status)->toBe('computed')
        ->and(sheet($c, $run->id))->toBe(['1000' => 115000, '3000' => -100000, '4000' => -20000, '5000' => 5000])
        ->and(sheet($c, $run->id, 'translated', 'S'))->toBe(sheet($c, $run->id, 'local', 'S'))
        ->and($run->summary)->toMatchArray(['profit' => 15000, 'translation_difference' => 0])
        ->and(ConsolidationRunMember::query()->where('run_id', $run->id)->where('member_id', $c->members['S']->id)->value('window_from')->format('Y-m-d'))->toBe('2026-01-04')
        ->and(collect($run->warnings)->pluck('code')->unique()->all())->toBe(['period_not_closed']);   // warns, does not refuse

    $approved = app(ManageConsolidationRun::class)->approve($checker, $run->id);

    expect($approved->status)->toBe('approved')->and($approved->approved_by)->toBe($checker->id)
        ->and($c->booksFingerprint())->toBe($before);
});

it('translates a KES subsidiary into USD: closing, daily-mean average and historical rates, the reserve balancing the column', function (): void {
    [$c, $checker] = kesGroup();
    $before = $c->booksFingerprint();

    $run = app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-01-01', '2026-06-30');

    // K's window is 1 Apr to 30 Jun (91 days). Rate in force: 0.0080 for 60 days, 0.0076 for 30, 0.0075 for 1.
    // Average = (0.48 + 0.228 + 0.0075) ÷ 91 = 0.00786263736… → 0.00786264.
    // Cash 115,000,000 × 0.0075 = 862,500 (closing). Revenue −30,000,000 × 0.00786264 = −235,879; expense 78,626 (average).
    // Capital −800,000 (at acquisition, 0.0080). Dividend 5,000,000 at its own day's rate 0.0076 = 38,000 (historical).
    // Reserve = −(862,500 − 800,000 + 38,000 − 235,879 + 78,626) = 56,753, a debit: KES weakened.
    $member = ConsolidationRunMember::query()->where('run_id', $run->id)->where('member_id', $c->members['K']->id)->firstOrFail();
    expect($member->average_rate)->toBe('0.00786264')->and($member->closing_rate)->toBe('0.00750000')
        ->and($member->rate_detail['average']['method'])->toBe('daily_mean')
        ->and(sheet($c, $run->id, 'translated', 'K'))->toBe(['1000' => 862500, '3000' => -800000, '3200' => 38000, '4000' => -235879, '5000' => 78626, 'G3200' => 56753])
        ->and(array_sum(sheet($c, $run->id, 'translated', 'K')))->toBe(0)
        // Consolidated: P's cash 200,000 + 862,500; investment 800,000 − 800,000; capital −1,000,000 − 800,000 + 800,000.
        ->and(sheet($c, $run->id))->toBe(['1000' => 1062500, '3000' => -1000000, '3200' => 38000, '4000' => -235879, '5000' => 78626, 'G3200' => 56753])
        ->and($run->summary)->toMatchArray(['profit' => 157253, 'translation_difference' => 56753]);

    app(ManageConsolidationRun::class)->approve($checker, $run->id);
    $c->rate('P', 'KES', 'USD', '2026-09-30', '0.0070');

    // The next quarter has no activity. Equity keeps its historical values (capital −800,000, dividend 38,000, and the
    // quarter's profit at the average, −157,253, now in retained earnings); cash 115,000,000 × 0.0070 = 805,000.
    // Reserve = −(805,000 − 800,000 + 38,000 − 157,253) = 114,253; the quarter's difference 57,500 = 115,000,000 × 0.0005.
    $next = app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-07-01', '2026-09-30');

    expect(sheet($c, $next->id, 'translated', 'K'))->toBe(['1000' => 805000, '3000' => -800000, '3200' => 38000, 'G3000' => -157253, 'G3200' => 114253])
        ->and($next->summary)->toMatchArray(['profit' => 0, 'translation_difference' => 57500])
        ->and($c->booksFingerprint())->toBe($before);
});

it('uses the group override for the average rate, recording the reason', function (): void {
    [$c] = kesGroup();
    app(ManageConsolidationGroup::class)->setRateOverride($c->actor(), $c->group->id, 'KES', '2026-01-01', '2026-06-30', '0.0078', 'Sales fell in June');

    $run = app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-01-01', '2026-06-30');
    $member = ConsolidationRunMember::query()->where('run_id', $run->id)->where('member_id', $c->members['K']->id)->firstOrFail();

    // Revenue −30,000,000 × 0.0078 = −234,000; expense 78,000.
    expect($member->average_rate)->toBe('0.0078')
        ->and($member->rate_detail['average'])->toMatchArray(['method' => 'override', 'reason' => 'Sales fell in June'])
        ->and(sheet($c, $run->id, 'translated', 'K'))->toMatchArray(['4000' => -234000, '5000' => 78000]);
});

it('blocks on an unmapped account with a balance, listing it, and on a missing rate', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'EUR');
    $c->group();
    $c->subsidiary('S', '2026-01-03', 10000);
    $c->mapAll('P');
    $c->post('S', '2026-01-02', [['1000', 5000, 0], ['3000', 0, 5000]]);

    try {
        app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-01-01', '2026-02-28');
        $this->fail('expected a block');
    } catch (DomainRuleViolation $e) {
        expect($e->errorCode)->toBe('consolidation_blocked')
            ->and(collect($e->violations)->pluck('code')->unique()->sort()->values()->all())->toBe(['fx_rate_missing', 'unmapped_account'])
            ->and(collect($e->violations)->where('code', 'unmapped_account'))->toHaveCount(2);
    }
});

it('blocks a foreign subsidiary acquired before the first run, whose equity has no historical rates', function (): void {
    [$c] = kesGroup();

    try {
        app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-05-01', '2026-06-30');
        $this->fail('expected a block');
    } catch (DomainRuleViolation $e) {
        expect(collect($e->violations)->pluck('code')->all())->toContain('consolidation_opening_missing')
            ->and(collect($e->violations)->firstWhere('code', 'consolidation_opening_missing')->message)->toContain('2026-04-01');
    }
});

it('blocks the next run when equity was posted into a period already consolidated', function (): void {
    [$c, $checker] = kesGroup();
    $runs = app(ManageConsolidationRun::class);
    $runs->approve($checker, $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-06-30')->id);
    $c->rate('P', 'KES', 'USD', '2026-09-30', '0.0070');
    $c->post('K', '2026-05-05', [['1000', 1000000, 0], ['3000', 0, 1000000]]);   // back-dated into the approved quarter

    expect(fn () => $runs->create($c->actor(), $c->group->id, '2026-07-01', '2026-09-30'))->toThrow(DomainRuleViolation::class, 'changed before this period');
});

it('refuses the maker approving their own run, and a stale run', function (): void {
    [$c, $checker] = sameCurrencyGroup();
    $runs = app(ManageConsolidationRun::class);
    $run = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-02-28');

    expect(fn () => $runs->approve($c->actor(), $run->id))->toThrow(DomainRuleViolation::class, 'second person');

    $c->post('S', '2026-02-20', [['1000', 100, 0], ['4000', 0, 100]]);   // the member's books move after the run read them

    expect(fn () => $runs->approve($checker, $run->id))->toThrow(DomainRuleViolation::class, 'books changed');
});

it('refuses approval when the group\'s entries changed after compute, though no book moved', function (): void {
    [$c, $checker] = sameCurrencyGroup();
    $runs = app(ManageConsolidationRun::class);
    $run = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-02-28');
    groupEntry($c, $checker, '2026-02-15', [['5000', 100], ['2000', -100]]);

    expect(fn () => $runs->approve($checker, $run->id))->toThrow(DomainRuleViolation::class, 'changed after this run was computed');
});

it('keeps one live run per period: a second is refused until the first is voided or reversed', function (): void {
    [$c, $checker] = sameCurrencyGroup();
    $runs = app(ManageConsolidationRun::class);
    $first = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-02-28');

    expect(fn () => $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-02-28'))->toThrow(DomainRuleViolation::class, 'already has a run');

    $runs->void($c->actor(), $first->id, 'Recompute');
    $second = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-02-28');
    $runs->approve($checker, $second->id);

    expect(fn () => $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-02-28'))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $runs->create($c->actor(), $c->group->id, '2026-03-15', '2026-03-31'))->toThrow(DomainRuleViolation::class, 'next period starts on 2026-03-01');

    $runs->reverse($checker, $second->id, 'Wrong mapping');

    expect($second->fresh()->status)->toBe('reversed')
        ->and($runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-02-28')->status)->toBe('computed');
});

it('reverses only the latest approved run', function (): void {
    [$c, $checker] = sameCurrencyGroup();
    $runs = app(ManageConsolidationRun::class);
    $jan = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31');
    $runs->approve($checker, $jan->id);
    $runs->approve($checker, $runs->create($c->actor(), $c->group->id, '2026-02-01', '2026-02-28')->id);

    expect(fn () => $runs->reverse($checker, $jan->id, 'x'))->toThrow(DomainRuleViolation::class, 'reverse the later run first');
});

it('numbers the consolidation ledger without gaps and corrects a posted entry only by a mirroring reversal', function (): void {
    [$c, $checker] = sameCurrencyGroup();   // the acquisition entry is CE-2026-00001
    $entries = app(ManageConsolidationEntry::class);
    $draft = $entries->draftManual($c->actor(), new EntryDraftInput($c->group->id, 'manual', '2026-02-15', [
        ['account_id' => $c->groupAccounts['5000'], 'net' => 700], ['account_id' => $c->groupAccounts['2000'], 'net' => -700],
    ], 'Accrual'));
    $voided = $entries->draftManual($c->actor(), new EntryDraftInput($c->group->id, 'manual', '2026-02-15', [
        ['account_id' => $c->groupAccounts['5000'], 'net' => 1], ['account_id' => $c->groupAccounts['2000'], 'net' => -1],
    ], 'Mistake'));
    $entries->void($c->actor(), $voided->id);   // a draft never had a number, so none is lost

    expect(fn () => $entries->approve($c->actor(), $draft->id))->toThrow(DomainRuleViolation::class, 'second person')
        ->and(fn () => $entries->draftManual($c->actor(), new EntryDraftInput($c->group->id, 'manual', '2026-02-15', [
            ['account_id' => $c->groupAccounts['5000'], 'net' => 5], ['account_id' => $c->groupAccounts['2000'], 'net' => -4],
        ], 'Unbalanced')))->toThrow(DomainRuleViolation::class, 'equal debits and credits')
        ->and(fn () => $entries->draftManual($c->actor(), new EntryDraftInput($c->group->id, 'manual', '2026-02-15', [
            ['account_id' => $c->groupAccounts['5000'], 'member_id' => (string) Str::uuid7(), 'net' => 5], ['account_id' => $c->groupAccounts['2000'], 'net' => -5],
        ], 'Stranger')))->toThrow(DomainRuleViolation::class, 'not in the group');

    $posted = $entries->approve($checker, $draft->id);
    $reversal = $entries->reverse($checker, $posted->id, 'Accrued twice');

    $all = ConsolidationEntry::query()->whereNotNull('number')->orderBy('number')->get();
    expect($all->pluck('number')->all())->toBe(['CE-2026-00001', 'CE-2026-00002', 'CE-2026-00003'])
        ->and($posted->fresh()->status)->toBe('reversed')->and($posted->fresh()->reversed_by_id)->toBe($reversal->id)
        ->and($reversal->reverses_entry_id)->toBe($posted->id)
        ->and($reversal->lines()->orderBy('line_no')->get()->map(fn ($l) => [$l->debit, $l->credit])->all())->toBe([[0, 700], [700, 0]])
        // Both stay in the worksheet and cancel out.
        ->and(sheet($c, app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-01-01', '2026-02-28')->id))
        ->toBe(['1000' => 115000, '3000' => -100000, '4000' => -20000, '5000' => 5000]);
});

it('keeps posted entries, approved runs and frozen worksheets immutable in the database', function (): void {
    [$c, $checker] = sameCurrencyGroup();
    $entry = ConsolidationEntry::query()->where('status', 'posted')->firstOrFail();
    $runs = app(ManageConsolidationRun::class);
    $run = $runs->approve($checker, $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-02-28')->id);

    expect(fn () => DB::table('consolidation_entries')->where('id', $entry->id)->update(['memo' => 'changed']))->toThrow(QueryException::class, 'only whitelisted')
        ->and(fn () => DB::table('consolidation_entries')->where('id', $entry->id)->update(['status' => 'draft']))->toThrow(QueryException::class, 'cannot move from posted')
        ->and(fn () => DB::table('consolidation_entries')->where('id', $entry->id)->delete())->toThrow(QueryException::class, 'never deleted')
        ->and(fn () => DB::table('consolidation_entry_lines')->where('entry_id', $entry->id)->update(['debit' => 1]))->toThrow(QueryException::class, 'never changed')
        ->and(fn () => DB::table('consolidation_entry_lines')->where('entry_id', $entry->id)->delete())->toThrow(QueryException::class, 'never changed')
        ->and(fn () => DB::table('consolidation_entry_lines')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $c->f->tenantId, 'entry_id' => $entry->id, 'line_no' => 9, 'group_account_id' => $c->groupAccounts['1000'], 'debit' => 5, 'credit' => 0,
        ]))->toThrow(QueryException::class, 'is posted')
        ->and(fn () => DB::table('consolidation_runs')->where('id', $run->id)->update(['period_to' => '2026-03-31']))->toThrow(QueryException::class, 'only whitelisted')
        ->and(fn () => DB::table('consolidation_runs')->where('id', $run->id)->update(['status' => 'void']))->toThrow(QueryException::class, 'cannot move from approved')
        ->and(fn () => DB::table('consolidation_runs')->where('id', $run->id)->delete())->toThrow(QueryException::class, 'never deleted')
        ->and(fn () => DB::table('consolidation_run_lines')->where('run_id', $run->id)->update(['amount' => 1]))->toThrow(QueryException::class, 'never changed')
        ->and(fn () => DB::table('consolidation_run_members')->where('run_id', $run->id)->delete())->toThrow(QueryException::class, 'never changed');
});

it('refuses a posted entry that does not balance at commit, in the database', function (): void {
    [$c] = sameCurrencyGroup();
    $id = (string) Str::uuid7();
    DB::table('consolidation_entries')->insert([
        'id' => $id, 'tenant_id' => $c->f->tenantId, 'group_id' => $c->group->id, 'status' => 'draft', 'kind' => 'manual', 'entry_date' => '2026-02-15', 'total_debit' => 5, 'total_credit' => 5,
    ]);
    DB::table('consolidation_entry_lines')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $c->f->tenantId, 'entry_id' => $id, 'line_no' => 1, 'group_account_id' => $c->groupAccounts['1000'], 'debit' => 5, 'credit' => 0,
    ]);

    expect(fn () => DB::transaction(function () use ($id, $c): void {
        DB::table('consolidation_entries')->where('id', $id)->update(['status' => 'posted', 'number' => 'CE-2026-09999', 'posted_at' => now(), 'approved_by' => $c->person('x')->id]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class, 'consolidation_entry_balanced');
});

it('serves runs and the worksheet over the API, with approval left to a second person', function (): void {
    [$c, $checker] = kesGroup();
    $owner = User::query()->findOrFail($c->f->userId);

    $id = $this->actingAs($owner)->postJson("/api/v1/consolidation/groups/{$c->group->id}/runs", ['from' => '2026-01-01', 'to' => '2026-06-30'])
        ->assertCreated()->assertJsonPath('data.status', 'computed')->assertJsonPath('data.can.approve', false)
        ->assertJsonPath('data.translation_difference.amount', 56753)->json('data.id');

    $sheet = $this->getJson("/api/v1/consolidation/runs/{$id}/worksheet")->assertOk()->json('data');
    $reserve = collect($sheet['lines'])->firstWhere(fn ($l) => $l['kind'] === 'consolidated' && $l['code'] === 'G3200');
    expect($reserve['amount']['amount'])->toBe(56753)
        ->and(collect($sheet['members'])->firstWhere('member_id', $c->members['K']->id)['average_rate'])->toBe('0.00786264');

    $this->postJson("/api/v1/consolidation/runs/{$id}/approve")->assertForbidden();
    $this->actingAs(User::query()->findOrFail($checker->id))->postJson("/api/v1/consolidation/runs/{$id}/approve")
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', $checker->id);
});
