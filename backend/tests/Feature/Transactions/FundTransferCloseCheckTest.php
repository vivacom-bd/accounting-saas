<?php

declare(strict_types=1);

use Tests\Support\MakesApiCalls;
use Tests\Support\TradeApi;
use Tests\Support\TransactionsApi;

uses(MakesApiCalls::class, TradeApi::class, TransactionsApi::class);

/*
 * The close checklist item for money in transit (plan 3c.12a): a transfer sent and not received by the period's last day is listed
 * next to the funds-in-transit balance. It warns and never blocks; receiving it clears the warning.
 */

beforeEach(function (): void {
    $this->setUpTrade();
    $this->setUpTransactions();
});

it('warns about a transfer still in transit at period end, shows the balance beside it, and does not block the close', function (): void {
    $draft = $this->draftDoc('fund-transfers', [
        'from_bank_account_id' => $this->bank['1200'], 'to_bank_account_id' => $this->bank['1000'], 'send_date' => '2026-03-10',
        'from_amount_fc' => 100000, 'to_amount_fc' => 110000, 'reference' => 'TRF-1',
    ]);
    $sent = $this->submitDoc('fund-transfers', $draft['id']);
    $period = $this->inTenant(fn () => $this->f->period('2026-03-15')->id);
    $check = fn (): array => collect($this->asPerson($this->owner)->getJson($this->api("fiscal-periods/{$period}/close-checks"))->assertOk()->json('data'))->keyBy('code')['TRANSFERS_IN_TRANSIT'];

    $c = $check();
    expect($c['passed'])->toBeTrue()->and($c['detail']['warning'])->toBeTrue()->and($c['detail']['open_transfers'])->toBe(1)
        ->and($c['detail']['open_total'])->toBe(110000)->and($c['detail']['in_transit_balance'])->toBe(110000)->and($c['detail']['difference'])->toBe(0)
        ->and($c['detail']['transfers'][0]['number'])->toBe($sent['number']);

    // Received after the period ends: still in transit at the end of March.
    $this->asPerson($this->accountant, signing: true)->postJson($this->api("fund-transfers/{$sent['id']}/receive"), ['received_on' => '2026-04-02'], $this->key())->assertOk();
    expect($check()['detail']['open_transfers'])->toBe(1);
    // Other checks may still fail (the EUR account is unrevalued); this one is never among the reasons.
    $closing = $this->asPerson($this->owner, signing: true)->postJson($this->api("fiscal-periods/{$period}/close"), [], $this->key());
    expect(json_encode($closing->json('violations')))->not->toContain('TRANSFERS_IN_TRANSIT');
});

it('is quiet when every transfer was received by the last day', function (): void {
    $draft = $this->draftDoc('fund-transfers', [
        'from_bank_account_id' => $this->bank['1200'], 'to_bank_account_id' => $this->bank['1000'], 'send_date' => '2026-03-10',
        'from_amount_fc' => 100000, 'to_amount_fc' => 110000,
    ]);
    $sent = $this->submitDoc('fund-transfers', $draft['id']);
    $this->asPerson($this->accountant, signing: true)->postJson($this->api("fund-transfers/{$sent['id']}/receive"), ['received_on' => '2026-03-12'], $this->key())->assertOk();
    $period = $this->inTenant(fn () => $this->f->period('2026-03-15')->id);
    $c = collect($this->asPerson($this->owner)->getJson($this->api("fiscal-periods/{$period}/close-checks"))->assertOk()->json('data'))->keyBy('code')['TRANSFERS_IN_TRANSIT'];
    expect($c['passed'])->toBeTrue()->and($c['detail']['warning'])->toBeFalse()->and($c['detail']['in_transit_balance'])->toBe(0);
});
