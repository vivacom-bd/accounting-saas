<?php

declare(strict_types=1);

use App\Services\Tax\CountryPacks;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerChecks;
use Tests\Support\MakesApiCalls;
use Tests\Support\TradeApi;
use Tests\Support\TransactionsApi;

uses(MakesApiCalls::class, TradeApi::class, TransactionsApi::class);

/*
 * Country packs (plan 3c.22): data, applied idempotently and additively, laid over the tax return through the company's own mapping.
 * Without a pack the tax return is exactly what it was.
 */

beforeEach(function (): void {
    $this->setUpTrade();
    $this->setUpTransactions();
    $this->boss = $this->person('Fay Finance', ['Finance Manager']);
    $this->apply = fn () => $this->asPerson($this->boss)->postJson($this->api('tax-pack/apply'), ['pack_key' => 'ke'], $this->key());
    $this->ret = fn (string $q = 'from=2026-03-01&to=2026-03-31') => $this->asPerson($this->owner)->getJson($this->api("reports/tax-return?{$q}"))->assertOk()->json('data');
    $this->codeId = fn (string $code) => $this->inTenant(fn () => DB::table('tax_codes')->where('company_id', $this->f->companyId)->where('code', $code)->value('id'));
});

function section(array $return, string $key): array
{
    return collect($return['pack']['sections'])->firstWhere('key', $key);
}

it('ships a well-formed Kenya pack: sourced rates, secondary sources marked, no box numbers', function (): void {
    $pack = app(CountryPacks::class)->find('ke');
    expect($pack['filing']['due_days_after_period_end'])->toBe(20)->and($pack['reverse_charge_mode'])->toBe('net_non_creditable');
    $rates = collect($pack['tax_codes'])->mapWithKeys(fn ($c) => [$c['code'] => $c['rates'][0]]);
    expect($rates['KE-VAT16']['rate'])->toBe('0.160000')->and($rates['KE-VAT0']['rate'])->toBe('0.000000')
        ->and($rates['KE-WHT-MGMT-R']['rate'])->toBe('0.050000')->and($rates['KE-WHT-MGMT-NR']['rate'])->toBe('0.200000')
        ->and($rates['KE-WHT-GOODS-R']['rate'])->toBe('0.005000')->and($rates['KE-WHT-RENT-NR']['rate'])->toBe('0.300000');
    $secondary = collect($pack['sources'])->where('secondary', true)->pluck('id')->all();
    expect($secondary)->toBe(['pwc']);
    foreach ($pack['tax_codes'] as $c) {
        expect($c['rates'][0]['as_of'])->toBe('2026-11-05')->and($c['kind'] === 'wht' ? $c['rates'][0]['source'] : 'pwc')->toBe('pwc');
    }
    $classes = collect($pack['depreciation_classes'])->keyBy('key');
    expect($classes['ke_aircraft']['first_year_bp'])->toBe(5000)->and($classes['ke_aircraft']['annual_bp'])->toBe(2500)
        ->and($classes['ke_computers']['annual_bp'])->toBe(2500)->and($classes['ke_furniture']['annual_bp'])->toBe(1000)
        ->and($classes['ke_commercial_building']['secondary'])->toBeTrue()->and($classes['ke_vehicles']['secondary'])->toBeFalse();
    expect(json_encode($pack))->not->toContain('box_no')->and(collect($pack['notes'])->pluck('text')->implode(' '))->toContain('not mapped');
});

it('leaves the tax return exactly as it was when no pack is applied', function (): void {
    $this->sale(10000, '2026-03-10');
    $r = ($this->ret)();
    expect($r)->not->toHaveKey('pack')->and(array_keys($r))->toBe(['generated_at', 'report', 'currency', 'from', 'to', 'sales', 'purchases', 'reverse_charge', 'net_tax_payable'])
        ->and($r['net_tax_payable']['amount'])->toBe(1000);
});

it('applies the pack once: creates codes with sourced rates, sections, mappings and a few accounts, and a second apply changes nothing', function (): void {
    $first = ($this->apply)()->assertOk()->json('data.result');
    expect($first['created']['tax_codes'])->toBe(22)->and($first['created']['sections'])->toBe(10)->and($first['created']['accounts'])->toBe(3)->and($first['created']['mappings'])->toBeGreaterThan(8);
    $rate = $this->inTenant(fn () => DB::table('tax_rates')->join('tax_codes', 'tax_codes.id', '=', 'tax_rates.tax_code_id')->where('tax_codes.code', 'KE-VAT16')->first(['tax_rates.rate', 'tax_rates.source', 'tax_rates.source_as_of']));
    expect($rate->rate)->toBe('0.160000')->and($rate->source)->toContain('VAT Act')->and($rate->source_as_of)->toBe('2026-11-05');
    $wht = $this->inTenant(fn () => DB::table('tax_rates')->join('tax_codes', 'tax_codes.id', '=', 'tax_rates.tax_code_id')->where('tax_codes.code', 'KE-WHT-MGMT-R')->value('tax_rates.source'));
    expect($wht)->toContain('secondary source, verify against ITA Fourth Schedule');

    // The company edits: renames a code, removes a mapping. A second apply keeps both and creates nothing.
    $this->inTenant(function (): void {
        DB::table('tax_codes')->where('code', 'KE-VAT16')->where('company_id', $this->f->companyId)->update(['name' => 'Our VAT']);
    });
    $this->asPerson($this->boss)->putJson($this->api('tax-pack/sections/output_zero/codes'), ['codes' => []], $this->key())->assertOk();
    $counts = fn () => $this->inTenant(fn () => [DB::table('tax_codes')->count(), DB::table('tax_rates')->count(), DB::table('tax_return_sections')->count(), DB::table('tax_return_section_codes')->count(), DB::table('accounts')->count()]);
    $before = $counts();
    $second = ($this->apply)()->assertOk()->json('data.result');
    expect($second['created'])->toBe(['tax_codes' => 0, 'rates' => 0, 'sections' => 0, 'mappings' => 0, 'accounts' => 0])->and($counts())->toBe($before)
        ->and($this->inTenant(fn () => DB::table('tax_codes')->where('code', 'KE-VAT16')->value('name')))->toBe('Our VAT');
    expect($this->inTenant(fn () => DB::table('audit_events')->where('action', 'country_pack.applied')->count()))->toBe(2);
    $this->asPerson($this->assistant)->postJson($this->api('tax-pack/apply'), ['pack_key' => 'ke'], $this->key())->assertForbidden();
    $this->asPerson($this->boss)->postJson($this->api('tax-pack/apply'), ['pack_key' => 'zz'], $this->key())->assertStatus(422);
});

it('lays the return out in the pack sections and every section figure is a total per tax code', function (): void {
    ($this->apply)()->assertOk();
    $this->inTenant(function (): void {
        foreach (['KE-VAT16', 'KE-VAT0', 'KE-VAT-EX', 'KE-VAT16-RC', 'KE-VAT16-RC-NC'] as $c) {
            $this->t->tax[$c] = DB::table('tax_codes')->where('company_id', $this->f->companyId)->where('code', $c)->value('id');
        }
    });
    $this->postedDoc('invoices', $this->t->body($this->t->customer, [[10000, '1', 'KE-VAT16']], '2026-03-10'));           // output 1,600
    $this->postedDoc('invoices', $this->t->body($this->t->customer, [[4000, '1', 'KE-VAT0']], '2026-03-11'));             // zero-rated 4,000
    $this->postedDoc('invoices', $this->t->body($this->t->customer, [[2500, '1', 'KE-VAT-EX']], '2026-03-12'));           // exempt 2,500
    $this->postedDoc('bills', $this->t->body($this->t->supplier, [[5000, '1', 'KE-VAT16', '0', '5000']], '2026-03-13'));  // input 800
    $this->postedDoc('bills', $this->t->body($this->t->supplier, [[10000, '1', 'KE-VAT16-RC', '0', '5000']], '2026-03-14')); // reverse charge, creditable: 1,600 out and in
    $this->postedDoc('bills', $this->t->body($this->t->supplier, [[2000, '1', 'KE-VAT16-RC-NC', '0', '5000']], '2026-03-15')); // not creditable: 320 out, input is cost

    $r = ($this->ret)();
    $amount = fn (string $key, string $part = 'amount') => section($r, $key)[$part]['amount'];
    expect($amount('output_standard'))->toBe(1600)->and($amount('output_standard', 'net'))->toBe(10000)
        ->and($amount('output_zero'))->toBe(4000)->and($amount('output_exempt'))->toBe(2500)
        ->and($amount('input_local'))->toBe(800)
        // net_non_creditable (the Kenya mode): the creditable reverse charge declares zero, the other declares its 320 only.
        ->and($r['pack']['reverse_charge_mode'])->toBe('net_non_creditable')
        ->and($amount('output_deemed_imported_services'))->toBe(320)->and($amount('input_imported_services'))->toBe(0)
        ->and($r['pack']['unmapped'])->toBe([])
        // 1,600 + 320 - 800 = 1,120, which is also the ledger's net tax payable (output less input, reverse charge included).
        ->and($r['pack']['net']['payable']['amount'])->toBe(1120)->and($r['net_tax_payable']['amount'])->toBe(1120);
    $this->inTenant(fn () => expect(LedgerChecks::problems())->toBe([]));

    // The company switches to gross: both sides declared, the net is the same.
    $settings = $this->asPerson($this->boss)->getJson($this->api('tax-pack'))->assertOk()->json('data.applied');
    $this->asPerson($this->boss)->putJson($this->api('tax-pack/settings'), ['version' => $settings['version'], 'authority' => 'KRA', 'due_days_after_period' => 20, 'reverse_charge_mode' => 'gross'], $this->key())->assertOk();
    $g = ($this->ret)();
    expect(section($g, 'output_deemed_imported_services')['amount']['amount'])->toBe(1920)->and(section($g, 'input_imported_services')['amount']['amount'])->toBe(1600)
        ->and($g['pack']['net']['payable']['amount'])->toBe(1120);

    // A mapping edit moves a code to another section; an unmapped code with activity is reported, never dropped.
    $this->asPerson($this->boss)->putJson($this->api('tax-pack/sections/output_zero/codes'), ['codes' => []], $this->key())->assertOk();
    $u = ($this->ret)();
    expect(section($u, 'output_zero')['amount']['amount'])->toBe(0)->and($u['pack']['unmapped'][0]['code'])->toBe('KE-VAT0')
        ->and($u['pack']['unmapped'][0]['net']['amount'])->toBe(4000);
    $this->asPerson($this->boss)->putJson($this->api('tax-pack/sections/adjustments/codes'), ['codes' => [['tax_code_id' => ($this->codeId)('KE-VAT0'), 'side' => 'sales']]], $this->key())->assertStatus(422);
    $this->asPerson($this->boss)->putJson($this->api('tax-pack/sections/output_zero/codes'), ['codes' => [['tax_code_id' => ($this->codeId)('KE-VAT0'), 'side' => 'purchases']]], $this->key())->assertOk();
    expect(section(($this->ret)(), 'output_zero')['amount']['amount'])->toBe(0);
});

it('takes manual sections from the tax period, and credit exceeding the tax carries forward', function (): void {
    ($this->apply)()->assertOk();
    $this->inTenant(fn () => $this->t->tax['KE-VAT16'] = DB::table('tax_codes')->where('company_id', $this->f->companyId)->where('code', 'KE-VAT16')->value('id'));
    $this->postedDoc('invoices', $this->t->body($this->t->customer, [[10000, '1', 'KE-VAT16']], '2026-03-10'));    // output 1,600
    $made = $this->asPerson($this->accountant)->postJson($this->api('tax-periods'), ['from' => '2026-03-01', 'months' => 1], $this->key())->assertCreated()->json('data.0');
    expect($made['authority'])->toBe('KRA')->and($made['due_on'])->toBe('2026-04-20');

    $saved = $this->asPerson($this->accountant)->putJson($this->api("tax-periods/{$made['id']}/amounts"), ['version' => 0, 'amounts' => ['withholding_vat_credit' => 2000, 'adjustments' => -100]], $this->key())->assertOk()->json('data');
    $d = $this->asPerson($this->boss)->getJson($this->api("tax-periods/{$made['id']}"))->assertOk()->json('data');
    // 1,600 - 100 adjustment - 2,000 withholding credit = -500: nothing payable, 500 carried forward.
    expect($d['return']['pack']['net']['payable']['amount'])->toBe(0)->and($d['return']['pack']['net']['carried_forward']['amount'])->toBe(500)
        ->and(section($d['return'], 'withholding_vat_credit')['manual'])->toBeTrue();

    // Filed, then a late sale changes the pack figures too.
    $this->clock->advance('+60 days');
    $this->asPerson($this->boss)->postJson($this->api("tax-periods/{$made['id']}/file"), ['version' => $saved['version'], 'reference' => 'ITAX-9'], $this->key())->assertOk();
    $this->postedDoc('invoices', $this->t->body($this->t->customer, [[10000, '1', 'KE-VAT16']], '2026-03-20'));
    $d = $this->asPerson($this->boss)->getJson($this->api("tax-periods/{$made['id']}"))->assertOk()->json('data');
    expect($d['changed_since_filing'])->toBeTrue()
        ->and(collect($d['changes_since_filing'])->firstWhere('key', 'section:output_standard:amount')['difference'])->toBe(1600)
        ->and(collect($d['changes_since_filing'])->firstWhere('key', 'section_net:carried_forward')['difference'])->toBe(-500);
    $this->asPerson($this->accountant)->putJson($this->api("tax-periods/{$made['id']}/amounts"), ['version' => 3, 'amounts' => ['adjustments' => 1]], $this->key())->assertForbidden();
});
