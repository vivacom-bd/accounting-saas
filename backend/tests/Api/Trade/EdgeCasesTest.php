<?php

declare(strict_types=1);

use Tests\Support\LedgerChecks;

/*
 * Plan 3c.30 / 4.1 edge cases on trade documents. Already covered elsewhere: the rounding residue as a debit (InvoiceFlowTest), an
 * overpayment held on account and applied later (SettlementFlowTest), whole-settlement reversal.
 */

beforeEach(function (): void {
    $this->setUpTrade();
});

it('books the rounding residue as a credit when the lines round down and the receivable rounds up (D1)', function (): void {
    // EUR 0.50 × 3 at 1.0049: each line 0.50245 -> 0.50, three lines 1.50; the receivable 1.50 × 1.0049 = 1.50735 -> 1.51.
    $doc = $this->draftDoc('invoices', $this->t->body($this->t->customer, [[50, '1', null, null, '4010'], [50, '1', null, null, '4010'], [50, '1', null, null, '4010']], extra: ['currency' => 'EUR', 'fx_rate' => '1.0049']));
    expect($doc['total_fc']['amount'])->toBe(150)->and($doc['total']['amount'])->toBe(151);

    $posted = $this->submitDoc('invoices', $doc['id']);
    // The journal balances in base: Dr 151 = Cr 150 + Cr 1 on the rounding account, and nothing is invented elsewhere.
    expect($this->t->journalOf($posted['journal_id']))->toBe([
        '1100' => ['debit' => 151, 'credit' => 0],
        '4010' => ['debit' => 0, 'credit' => 150],
        '9999' => ['debit' => 0, 'credit' => 1],
    ]);
    $this->inTenant(fn () => expect(LedgerChecks::problems())->toBe([]));
});

it('applies the same rounding rule to a supplier bill, so the payable is what the supplier is owed', function (): void {
    $doc = $this->draftDoc('bills', $this->t->body($this->t->supplier, [[1001, '1', null, null, '5010'], [1001, '1', null, null, '5010'], [1001, '1', null, null, '5010']], extra: ['currency' => 'EUR', 'fx_rate' => '1.0725']));
    $posted = $this->submitDoc('bills', $doc['id']);

    // Payable 30.03 × 1.0725 = 32.2072 -> 32.21; expense lines 3 × 10.74 = 32.22; the cent goes to rounding.
    expect($this->t->journalOf($posted['journal_id']))->toBe([
        '2000' => ['debit' => 0, 'credit' => 3221],
        '5010' => ['debit' => 3222, 'credit' => 0],
        '9999' => ['debit' => 0, 'credit' => 1],
    ]);
    $this->inTenant(fn () => expect(LedgerChecks::problems())->toBe([]));
});

it('reverses a receipt that was partly allocated to two invoices with a remainder on account, restoring every open amount exactly', function (): void {
    $a = $this->sale();             // 11,000
    $b = $this->sale(5000);         // 5,500
    $before = fn (): array => [
        $this->document('invoices', $a['id'])['amount_due']['amount'], $this->document('invoices', $b['id'])['amount_due']['amount'],
        $this->balance('1100'), $this->balance('1000'),
    ];
    expect($before())->toBe([11000, 5500, 16500, 0]);

    // 20,000 received: 6,000 against the first, 5,500 against the second (clearing it), 8,500 on account.
    $r = $this->postedSettlement('receipt', $this->t->customer, 20000, [[$a['id'], 6000], [$b['id'], 5500]]);
    expect($r['unallocated_fc']['amount'])->toBe(8500)
        ->and($before())->toBe([5000, 0, 16500 - 20000, 20000]);
    expect($this->document('invoices', $b['id'])['payment_status'])->toBe('paid');

    $this->asPerson($this->owner, signing: true)->postJson($this->api("settlements/{$r['id']}/reverse"), [], $this->key())->assertOk()->assertJsonPath('data.status', 'reversed');

    expect($before())->toBe([11000, 5500, 16500, 0])
        ->and($this->document('invoices', $a['id'])['payment_status'])->toBe('awaiting_payment')
        ->and($this->document('invoices', $b['id'])['payment_status'])->toBe('awaiting_payment')
        ->and($this->balance('1000'))->toBe(0);
    // The 8,500 that sat on account is gone with the receipt: it cannot be applied to anything afterwards.
    $this->asPerson($this->owner, signing: true)->postJson($this->api("settlements/{$r['id']}/allocate"), ['invoice_id' => $a['id'], 'amount_fc' => 100], $this->key())->assertClientError();
    expect($this->document('invoices', $a['id'])['amount_due']['amount'])->toBe(11000);
    $this->inTenant(fn () => expect(LedgerChecks::problems())->toBe([]));
});
