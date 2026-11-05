<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\MakesApiCalls;
use Tests\Support\TradeApi;

uses(MakesApiCalls::class, TradeApi::class);

/*
 * Tax periods (D15 item 13, plan 3c.21a): monthly by default, filed with a frozen snapshot, filing never closes a ledger period, a
 * posting into a filed period is allowed while the ledger period is open and shows as "changed since filing" with the difference,
 * and a reopen needs a second person with a reason.
 */

beforeEach(function (): void {
    $this->setUpTrade();
    $this->boss = $this->person('Fay Finance', ['Finance Manager']);
});

function generatePeriods(object $test, string $from = '2026-03-01', int $months = 2): array
{
    return (fn () => $this->asPerson($this->accountant)->postJson($this->api('tax-periods'), ['from' => $from, 'months' => $months, 'authority' => 'Revenue'], $this->key())->assertCreated()->json('data'))->call($test);
}

it('generates calendar months once and refuses periods of other dates over them', function (): void {
    $made = generatePeriods($this);
    expect($made)->toHaveCount(2)->and($made[0]['starts_on'])->toBe('2026-03-01')->and($made[0]['ends_on'])->toBe('2026-03-31')->and($made[1]['ends_on'])->toBe('2026-04-30')->and($made[0]['status'])->toBe('open');
    // Running it again creates nothing.
    $this->asPerson($this->accountant)->postJson($this->api('tax-periods'), ['from' => '2026-03-01', 'months' => 2, 'authority' => 'Revenue'], $this->key())->assertCreated()->assertJsonPath('meta.created', 0);
    // Another authority has its own calendar.
    $this->asPerson($this->accountant)->postJson($this->api('tax-periods'), ['from' => '2026-03-01', 'months' => 1, 'authority' => 'Customs'], $this->key())->assertCreated()->assertJsonPath('meta.created', 1);
    expect($this->inTenant(fn () => DB::table('tax_periods')->count()))->toBe(3);
    // The sales assistant cannot generate.
    $this->asPerson($this->assistant)->postJson($this->api('tax-periods'), ['from' => '2026-05-01', 'months' => 1], $this->key())->assertForbidden();
});

it('files with a frozen snapshot, leaves the ledger period open, and shows what changed after a late posting', function (): void {
    $this->sale(10000, '2026-03-10');                                       // net 10,000, VAT 1,000
    $march = generatePeriods($this)[0];
    $this->clock->advance('+60 days');

    // The accountant may prepare but not file; a period cannot be filed before it ends (checked below with April).
    $this->asPerson($this->accountant)->postJson($this->api("tax-periods/{$march['id']}/file"), ['version' => 0, 'reference' => 'ACK-1'], $this->key())->assertForbidden();
    $filed = $this->asPerson($this->boss)->postJson($this->api("tax-periods/{$march['id']}/file"), ['version' => 0, 'reference' => 'ACK-1'], $this->key())->assertOk()->json('data');
    expect($filed['status'])->toBe('filed')->and($filed['authority_reference'])->toBe('ACK-1')->and($filed['filed_by'])->toBe($this->boss->id);

    $detail = $this->asPerson($this->boss)->getJson($this->api("tax-periods/{$march['id']}"))->assertOk()->json('data');
    expect($detail['changed_since_filing'])->toBeFalse()->and($detail['changes_since_filing'])->toBe([])
        ->and($detail['filed_return']['net_tax_payable']['amount'])->toBe(1000)->and($detail['return']['net_tax_payable']['amount'])->toBe(1000);

    // Filing closed no ledger period.
    expect($this->inTenant(fn () => DB::table('fiscal_periods')->where('company_id', $this->f->companyId)->where('status', '<>', 'open')->count()))->toBe(0);

    // A posting dated in the filed period still posts, and the period now differs from its snapshot.
    $this->sale(5000, '2026-03-20');                                        // net 5,000, VAT 500
    $detail = $this->asPerson($this->boss)->getJson($this->api("tax-periods/{$march['id']}"))->assertOk()->json('data');
    $diff = collect($detail['changes_since_filing'])->keyBy('label');
    expect($detail['changed_since_filing'])->toBeTrue()
        ->and($detail['filed_return']['net_tax_payable']['amount'])->toBe(1000)->and($detail['return']['net_tax_payable']['amount'])->toBe(1500)
        ->and($diff['Net tax payable']['difference'])->toBe(500)
        ->and($diff['Sales VAT10 net']['difference'])->toBe(5000)->and($diff['Sales VAT10 tax']['difference'])->toBe(500);
    $list = $this->asPerson($this->boss)->getJson($this->api('tax-periods'))->assertOk()->json('data');
    expect(collect($list)->firstWhere('id', $march['id'])['changed_since_filing'])->toBeTrue();
});

it('reopens only by a second person with a reason, and keeps the old snapshot in the audit trail', function (): void {
    $this->sale(10000, '2026-03-10');
    $march = generatePeriods($this)[0];
    $this->clock->advance('+60 days');
    $filed = $this->asPerson($this->boss)->postJson($this->api("tax-periods/{$march['id']}/file"), ['version' => 0, 'reference' => 'ACK-1'], $this->key())->assertOk()->json('data');

    $this->asPerson($this->boss)->postJson($this->api("tax-periods/{$march['id']}/reopen"), ['version' => $filed['version'], 'reason' => 'Late credit note'], $this->key())->assertForbidden();
    $this->asPerson($this->owner)->postJson($this->api("tax-periods/{$march['id']}/reopen"), ['version' => $filed['version'], 'reason' => 'x'], $this->key())->assertStatus(422);
    $open = $this->asPerson($this->owner)->postJson($this->api("tax-periods/{$march['id']}/reopen"), ['version' => $filed['version'], 'reason' => 'Late credit note'], $this->key())->assertOk()->json('data');
    expect($open['status'])->toBe('open')->and($open['reopen_reason'])->toBe('Late credit note')->and($open['reopened_by'])->toBe($this->owner->id);

    $event = $this->inTenant(fn () => DB::table('audit_events')->where('action', 'tax_period.reopened')->first());
    expect(json_decode((string) $event->before, true)['return_snapshot']['net_tax_payable']['amount'])->toBe(1000);
});

it('refuses to file a period that has not ended, a filing with no reference, and a stale version', function (): void {
    $march = generatePeriods($this)[0];
    $this->asPerson($this->boss)->postJson($this->api("tax-periods/{$march['id']}/file"), ['version' => 0, 'reference' => 'A'], $this->key())
        ->assertStatus(422)->assertJsonPath('violations.0.code', 'tax_period_not_ended');
    $this->clock->advance('+60 days');
    $this->asPerson($this->boss)->postJson($this->api("tax-periods/{$march['id']}/file"), ['version' => 0, 'reference' => ''], $this->key())->assertStatus(422);
    $this->asPerson($this->boss)->postJson($this->api("tax-periods/{$march['id']}/file"), ['version' => 7, 'reference' => 'A'], $this->key())->assertStatus(409);
});
