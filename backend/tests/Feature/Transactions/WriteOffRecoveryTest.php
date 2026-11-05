<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerChecks;
use Tests\Support\MakesApiCalls;
use Tests\Support\TradeApi;
use Tests\Support\TransactionsApi;

uses(MakesApiCalls::class, TradeApi::class, TransactionsApi::class);

/*
 * Recovery after write-off (plan 3c.12a, D15 items 2 and 9). An invoice of 50.00 + 10% VAT is 55.00 (5,500), written off in full
 * with no allowance (Dr bad-debt expense 5,500, Cr AR 5,500). A recovery of 20.00 posts Dr AR 2,000 (with the contact), Cr bad-debt
 * recovery 2,000, reopens the invoice by 2,000, and the money then arrives through an ordinary receipt of 2,000.
 */

beforeEach(function (): void {
    $this->setUpTrade();
    $this->setUpTransactions();
});

/** @return array{0: array<string, mixed>, 1: array<string, mixed>} the invoice and its posted write-off */
function writtenOff(object $test, int $price = 5000, ?int $amount = null): array
{
    return (function () use ($price, $amount): array {
        $invoice = $this->sale($price);
        $w = $this->draftDoc('write-offs', ['invoice_id' => $invoice['id'], 'write_off_date' => '2026-04-20', 'amount_fc' => $amount ?? 5500, 'reason' => 'Customer insolvent']);

        return [$invoice, $this->submitDoc('write-offs', $w['id'])];
    })->call($test);
}

/** @param array<string, mixed> $w @return array<string, mixed> */
function recoveryDraft(object $test, array $w, int $amount, string $date = '2026-05-10'): array
{
    return (fn (): array => $this->draftDoc('write-off-recoveries', ['write_off_id' => $w['id'], 'recovery_date' => $date, 'amount_fc' => $amount, 'reason' => 'Customer paid part after settlement talks']))->call($test);
}

it('posts Dr AR with the contact, Cr bad-debt recovery, reopens the invoice, and a normal receipt then clears it', function (): void {
    [$invoice, $w] = writtenOff($this);
    expect($w['status'])->toBe('posted')->and($this->net('1100'))->toBe(0)->and($this->document('invoices', $invoice['id'])['amount_due']['amount'])->toBe(0);
    $revenue = $this->balance('4010');
    $this->workflow('write_off_recovery');

    $r = recoveryDraft($this, $w, 2000);
    $this->asPerson($this->accountant, signing: true)->postJson($this->api("write-off-recoveries/{$r['id']}/submit"), [], $this->key())->assertOk()->assertJsonPath('data.status', 'pending_approval');
    $this->asPerson($this->accountant, signing: true)->postJson($this->api("write-off-recoveries/{$r['id']}/approve"), [], $this->key())->assertForbidden();
    $posted = $this->asPerson($this->approver, signing: true)->postJson($this->api("write-off-recoveries/{$r['id']}/approve"), [], $this->key())
        ->assertOk()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.number', 'WOR-FY2026-00001')->assertJsonPath('data.base_amount.amount', 2000)->json('data');

    expect($this->linesOf($posted['journal_id']))->toBe([['1100', 2000, 0], ['7105', 0, 2000]])
        ->and($this->net('1100'))->toBe(2000)->and($this->net('7105'))->toBe(-2000)->and($this->balance('4010'))->toBe($revenue);
    $this->inTenant(function () use ($invoice, $posted, $w): void {
        $line = DB::table('journal_lines')->where('journal_id', $posted['journal_id'])->where('account_id', $this->f->account('1100'))->first();
        expect($line->contact_id)->toBe($this->t->customer)->and((int) DB::table('invoices')->where('id', $invoice['id'])->value('settled'))->toBe(3500)
            ->and((int) DB::table('write_offs')->where('id', $w['id'])->value('recovered_fc'))->toBe(2000)
            ->and(LedgerChecks::problems())->toBe([]);
    });
    expect($this->document('invoices', $invoice['id'])['amount_due']['amount'])->toBe(2000)
        ->and($this->document('invoices', $invoice['id'])['written_off']['amount'])->toBe(3500)
        ->and($this->document('write-offs', $w['id'])['recoverable']['amount'])->toBe(3500);
    $aging = $this->asPerson($this->owner)->getJson($this->api('reports/ar-aging?as_of=2026-05-31'))->assertOk()->json('data');
    expect($aging['totals']['open']['amount'])->toBe(2000)->and($aging['totals']['open']['amount'])->toBe($this->net('1100'));

    // The money arrives through a normal receipt allocated to the reopened invoice.
    $this->postedSettlement('receipt', $this->t->customer, 2000, [[$invoice['id'], 2000]], '2026-05-15');
    expect($this->net('1100'))->toBe(0)->and($this->net('7105'))->toBe(-2000)->and($this->balance('4010'))->toBe($revenue)->and($this->balance('1000'))->toBe(2000);
    $after = $this->asPerson($this->owner)->getJson($this->api('reports/ar-aging?as_of=2026-05-31'))->assertOk()->json('data');
    expect($after['rows'])->toBe([])->and($this->document('invoices', $invoice['id'])['amount_due']['amount'])->toBe(0);
    $this->inTenant(fn () => expect(LedgerChecks::problems())->toBe([]));

    // The customer statement shows the write-off and the recovery as their own rows and ties to the control account.
    $s = $this->asPerson($this->owner)->getJson($this->api("reports/customer-statement?contact_id={$this->t->customer}&from=2026-03-01&to=2026-05-31"))->assertOk()->json('data');
    $types = array_map(fn (array $row): array => [$row['type'], $row['document']['number']], $s['rows']);
    expect($types)->toContain(['write_off', 'WO-FY2026-00001'])->toContain(['write_off_recovery', 'WOR-FY2026-00001'])
        ->and($s['closing_balance']['amount'])->toBe($this->net('1100'))->and($s['closing_balance']['amount'])->toBe(0);
});

it('refuses more than was written off, a recovery before the write-off, an unposted write-off and a closed period', function (): void {
    [, $w] = writtenOff($this);
    $post = fn (array $body) => $this->asPerson($this->accountant)->postJson($this->api('write-off-recoveries'), $body, $this->key());
    $base = ['write_off_id' => $w['id'], 'recovery_date' => '2026-05-10', 'amount_fc' => 100, 'reason' => 'Paid'];
    $post([...$base, 'amount_fc' => 5501])->assertStatus(422)->assertJsonPath('code', 'recovery_exceeds_written_off');
    $post([...$base, 'recovery_date' => '2026-04-19'])->assertStatus(422)->assertJsonPath('code', 'recovery_before_write_off');
    $post([...$base, 'reason' => '  '])->assertStatus(422);

    $r = recoveryDraft($this, $w, 1000);
    $this->inTenant(fn () => $this->f->setPeriodStatus('2026-05-10', 'closed'));
    $this->asPerson($this->accountant, signing: true)->postJson($this->api("write-off-recoveries/{$r['id']}/submit"), [], $this->key())->assertStatus(422);
    $this->inTenant(fn () => $this->f->setPeriodStatus('2026-05-10', 'open'));

    // Two recoveries cannot take more than the write-off between them.
    $b = recoveryDraft($this, $w, 5000);
    $a = $this->submitDoc('write-off-recoveries', $r['id']);
    $this->asPerson($this->accountant, signing: true)->postJson($this->api("write-off-recoveries/{$b['id']}/submit"), [], $this->key())->assertStatus(422)->assertJsonPath('code', 'recovery_exceeds_written_off');
    expect($a['status'])->toBe('posted')->and($this->net('1100'))->toBe(1000);

    // A write-off that has been recovered cannot be reversed until the recovery is.
    $this->asPerson($this->owner, signing: true)->postJson($this->api("write-offs/{$w['id']}/reverse"), [], $this->key())->assertStatus(422)->assertJsonPath('code', 'write_off_recovered');
});

it('reverses a recovery to restore everything, and refuses once a receipt has used the reopened amount', function (): void {
    [$invoice, $w] = writtenOff($this);
    $r = $this->submitDoc('write-off-recoveries', recoveryDraft($this, $w, 2000)['id']);
    $this->postedSettlement('receipt', $this->t->customer, 2000, [[$invoice['id'], 2000]], '2026-05-15');

    $this->asPerson($this->accountant, signing: true)->postJson($this->api("write-off-recoveries/{$r['id']}/reverse"), [], $this->key())->assertForbidden();
    $this->asPerson($this->owner, signing: true)->postJson($this->api("write-off-recoveries/{$r['id']}/reverse"), ['date' => '2026-05-20'], $this->key())
        ->assertStatus(422)->assertJsonPath('code', 'recovery_amount_used');

    // A second, unused recovery reverses cleanly.
    $r2 = $this->submitDoc('write-off-recoveries', recoveryDraft($this, $w, 1500, '2026-05-16')['id']);
    expect($this->net('1100'))->toBe(1500)->and($this->document('invoices', $invoice['id'])['amount_due']['amount'])->toBe(1500);
    $this->asPerson($this->owner, signing: true)->postJson($this->api("write-off-recoveries/{$r2['id']}/reverse"), ['date' => '2026-05-17'], $this->key())
        ->assertOk()->assertJsonPath('data.status', 'reversed');
    expect($this->net('1100'))->toBe(0)->and($this->net('7105'))->toBe(-2000)->and($this->document('invoices', $invoice['id'])['amount_due']['amount'])->toBe(0);
    $this->inTenant(function () use ($w): void {
        expect((int) DB::table('write_offs')->where('id', $w['id'])->value('recovered_fc'))->toBe(2000)->and(LedgerChecks::problems())->toBe([]);
    });
});

it('carries a foreign-currency recovery at the write-off rate and the final one takes the remaining base', function (): void {
    $eur = $this->t->contact('CE', 'customer', 'EUR');
    $invoice = $this->postedDoc('invoices', $this->t->body($eur, [[10000, '1', null, '0', '4010']], '2026-03-10', ['currency' => 'EUR']));
    $wd = $this->draftDoc('write-offs', ['invoice_id' => $invoice['id'], 'write_off_date' => '2026-04-20', 'amount_fc' => 10000, 'reason' => 'Insolvent']);
    expect($invoice['total']['amount'])->toBe(11000);   // EUR 100.00 at 1.10 is USD 110.00
    $w = $this->submitDoc('write-offs', $wd['id']);
    $first = $this->submitDoc('write-off-recoveries', recoveryDraft($this, $w, 3333)['id']);
    $rest = $this->submitDoc('write-off-recoveries', recoveryDraft($this, $w, 6667)['id']);
    $this->inTenant(function () use ($first, $rest, $w): void {
        $line = DB::table('journal_lines')->where('journal_id', $first['journal_id'])->where('account_id', $this->f->account('1100'))->first();
        expect($line->currency)->toBe('EUR')->and((int) $line->amount_fc)->toBe(3333)
            ->and((int) DB::table('write_off_recoveries')->whereIn('id', [$first['id'], $rest['id']])->sum('amount'))->toBe((int) DB::table('write_offs')->where('id', $w['id'])->value('amount'))
            ->and(LedgerChecks::problems())->toBe([]);
    });
});
