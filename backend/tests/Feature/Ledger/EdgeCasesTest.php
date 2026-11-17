<?php

declare(strict_types=1);

use App\Actions\Fiscal\ClosePeriod;
use App\Actions\Fiscal\Inputs\ClosePeriodInput;
use App\Actions\Fiscal\YearEndClose;
use App\Actions\Ledger\Inputs\ReverseJournalInput;
use App\Actions\Ledger\ReverseJournal;
use App\Domain\Ledger\DraftLine;
use App\Domain\Ledger\PostingRejected;
use App\Support\Actor;
use App\Support\ActorType;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerChecks;
use Tests\Support\LedgerFixture;

/*
 * Plan 3c.30 / 4.1 edge cases on the ledger: period boundary dates against the company timezone and the UTC clock, and the year-end
 * close taken back and redone. (FX rounding, overpayment and settlement reversal live in tests/Api/Trade/EdgeCasesTest.php.)
 */

beforeEach(function (): void {
    $this->grant('periods.close', 'periods.year_end', 'periods.post_adjustment', 'journals.reverse');
    $this->f = LedgerFixture::create();
});

it('puts a journal in the period of its own date: first and last day belong to their month, and the days outside the year to none', function (): void {
    foreach (['2026-01-01', '2026-01-31', '2026-02-01', '2026-02-28', '2026-03-01', '2026-12-31'] as $date) {
        expect(cashSale($this->f, 100, $date)->fiscal_period_id)->toBe($this->f->period($date)->id);
    }
    expect($this->f->period('2026-01-31')->id)->not->toBe($this->f->period('2026-02-01')->id)
        ->and(LedgerChecks::problems())->toBe([]);

    foreach (['2025-12-31', '2027-01-01'] as $outside) {
        expect(fn () => cashSale($this->f, 100, $outside))->toThrow(PostingRejected::class);
    }
});

it('judges the date typed on the journal, not the UTC clock, whatever the company timezone', function (string $zone, string $utcNow): void {
    DB::table('companies')->where('id', $this->f->companyId)->update(['timezone' => $zone]);
    $this->f->setPeriodStatus('2026-03-31', 'closed');
    $this->clock->advance('+'.(int) ((strtotime($utcNow.' UTC') - $this->clock->now()->getTimestamp()) / 60).' minutes');
    expect($this->clock->now()->format('Y-m-d H:i'))->toBe(substr($utcNow, 0, 16));

    // March is closed whatever the wall clock says: the 31st is refused, the 1st lands in the open April.
    expect(fn () => cashSale($this->f, 100, '2026-03-31'))->toThrow(PostingRejected::class);
    $april = cashSale($this->f, 100, '2026-04-01');
    expect($april->journal_date->format('Y-m-d'))->toBe('2026-04-01')->and($april->fiscal_period_id)->toBe($this->f->period('2026-04-01')->id)
        ->and($april->posted_at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'))->toBe(substr($utcNow, 0, 16))
        ->and(LedgerChecks::problems())->toBe([]);
})->with([
    'Nairobi, ahead of UTC (already 1 April locally)' => ['Africa/Nairobi', '2026-03-31 23:30:00'],
    'Chicago, behind UTC (still 31 March locally)' => ['America/Chicago', '2026-04-01 01:30:00'],
]);

it('dates a reversal of a last-day posting into the next open period when that month has been closed meanwhile', function (): void {
    $original = cashSale($this->f, 700, '2026-01-31');
    $this->f->setPeriodStatus('2026-01-31', 'closed');
    $reversal = app(ReverseJournal::class)->handle($this->f->signedActor(), new ReverseJournalInput($original->id, null));

    expect($reversal->journal_date->format('Y-m-d'))->toBe('2026-02-01')->and($reversal->fiscal_period_id)->toBe($this->f->period('2026-02-01')->id)
        ->and(LedgerChecks::problems())->toBe([]);
});

it('takes a year-end close back: reopen a month, correct it, re-close; retained earnings moves with it and returns exactly when the correction is reversed', function (): void {
    $actor = fn (): Actor => new Actor(ActorType::User, $this->f->userId, $this->f->tenantId, $this->f->companyId, hasSigningGrant: true, requestId: 'test');
    $retained = fn (): int => (int) DB::table('account_period_balances')->where('account_id', $this->f->account('3000'))->sum(DB::raw('credit - debit'));
    $liveClosing = fn (): array => DB::table('journals')->where('journal_type', 'closing')->where('status', 'posted')->whereNull('reversal_of_id')->pluck('number')->all();
    $closeAll = function () use ($actor): void {
        foreach (range(1, 12) as $m) {
            $period = $this->f->period(sprintf('2026-%02d-15', $m));
            if ($period->status->value !== 'closed') {
                app(ClosePeriod::class)->handle($actor(), new ClosePeriodInput($period->id, []));
            }
        }
    };

    cashSale($this->f, 10000, '2026-02-10');
    postJournal($this->f, draftJournal($this->f, [new DraftLine($this->f->account('5000'), debit: 4000), new DraftLine($this->f->account('1000'), credit: 4000)], '2026-05-10'));
    $closeAll();
    $yearId = $this->f->period('2026-01-15')->fiscal_year_id;
    $first = app(YearEndClose::class)->handle($actor(), $yearId);
    expect($retained())->toBe(6000)->and($first?->number)->not->toBeNull();

    // May is reopened (the approval flow itself is covered in PeriodCloseTest) and a missed 1,000 expense goes in.
    $this->clock->advance('+1 day');
    $this->f->setPeriodStatus('2026-05-15', 'open');
    $correction = postJournal($this->f, draftJournal($this->f, [new DraftLine($this->f->account('5000'), debit: 1000), new DraftLine($this->f->account('1000'), credit: 1000)], '2026-05-20'));
    $closeAll();
    $second = app(YearEndClose::class)->handle($actor(), $yearId);
    expect($retained())->toBe(5000)->and($liveClosing())->toBe([$second?->number]);

    // Take the correction back the same way: reopen May, reverse it (dated in May), close again, close the year again.
    $this->clock->advance('+1 day');
    $this->f->setPeriodStatus('2026-05-15', 'open');
    app(ReverseJournal::class)->handle($actor(), new ReverseJournalInput($correction->id, null));
    $closeAll();
    $third = app(YearEndClose::class)->handle($actor(), $yearId);

    expect($retained())->toBe(6000)->and($liveClosing())->toBe([$third?->number])
        ->and($third?->lines()->where('account_id', $this->f->account('3000'))->value('credit'))->toBe(6000);
    $net = fn (string $code): int => (int) DB::table('account_period_balances')->where('account_id', $this->f->account($code))->sum(DB::raw('credit - debit'));
    // LedgerChecks scans every sequence for gaps and duplicates, and rechecks balances, the projection and the audit chain.
    expect([$net('4000'), $net('5000')])->toBe([0, 0])->and(LedgerChecks::problems())->toBe([]);
});
