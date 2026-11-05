<?php

declare(strict_types=1);

namespace App\Queries\Tax;

use App\Http\Support\Json;
use App\Models\Tax\CompanyTaxPack;
use App\Models\Tax\TaxReturnSection;
use App\Models\Tax\TaxReturnSectionCode;
use App\Services\Tax\CountryPacks;

/**
 * The country pack layer of the tax return (plan 3c.22): the totals per tax code that `TaxReturn` already computed, laid out in
 * the pack's labelled sections through the company's own mapping. It adds nothing to the ledger figures: a section sums the rows
 * of the codes mapped to it, so every figure here is one of the totals per code. Anything with activity that no section picks up
 * is listed as `unmapped` instead of being dropped, so the layout can be checked against the totals.
 *
 * Reverse charge (13 §23 D15 items 4 and 9): the ledger books output and input on the whole base; only the reporting differs.
 * `gross` declares both; `net_non_creditable` declares only the non-creditable part (output less input, a code being all creditable
 * or not at all), as the Kenya VAT Act s.10 requires. The net payable is the same either way.
 *
 * Sections entered by hand (adjustments, credits) take their figure from the tax period's manual amounts; outside a period they are 0.
 */
final class PackReturn
{
    public function __construct(private readonly CountryPacks $packs) {}

    /**
     * @param  array<string, mixed>  $base  the tax return report
     * @param  array<string, int>  $manual  section key → signed minor units
     * @return array<string, mixed>|null null when the company applied no pack
     */
    public function __invoke(string $companyId, array $base, array $manual = []): ?array
    {
        $state = CompanyTaxPack::query()->where('company_id', $companyId)->first();
        if ($state === null) {
            return null;
        }
        $cur = (string) $base['currency'];
        $sections = TaxReturnSection::query()->where('company_id', $companyId)->orderBy('sort')->get();
        $maps = TaxReturnSectionCode::query()->where('company_id', $companyId)->get()->groupBy('section_id');

        // Rows of the totals per code, keyed side:code id.
        $rows = [];
        foreach (['sales', 'purchases'] as $side) {
            foreach ((array) ($base[$side]['rows'] ?? []) as $r) {
                $rows[$side.':'.($r['tax_code']['id'] ?? 'none')] = ['code' => $r['tax_code']['code'] ?? null, 'net' => (int) $r['net']['amount'], 'tax' => (int) $r['tax']['amount'], 'recoverable' => ($r['recoverable'] ?? true) !== false, 'base' => 0, 'output' => 0, 'input' => 0, 'rc' => false];
            }
        }
        foreach ((array) ($base['reverse_charge']['rows'] ?? []) as $r) {
            $rows['reverse_charge:'.($r['tax_code']['id'] ?? 'none')] = ['code' => $r['tax_code']['code'] ?? null, 'net' => 0, 'tax' => 0, 'recoverable' => true, 'base' => (int) $r['base']['amount'], 'output' => (int) $r['output']['amount'], 'input' => (int) $r['input']['amount'], 'rc' => true];
        }

        $used = [];
        $out = [];
        $payable = 0;
        foreach ($sections as $s) {
            $net = 0;
            $tax = 0;
            foreach ($maps->get($s->id, collect()) as $m) {
                $key = $m->side.':'.$m->tax_code_id;
                $row = $rows[$key] ?? null;
                $used[$key] = true;
                if ($row === null) {
                    continue;
                }
                if ($s->source_kind === 'codes') {
                    $net += $row['net'];
                    // Tax on a non-recoverable purchase is cost, not input tax (as in the totals).
                    $tax += ($m->side === 'purchases' && $row['recoverable'] === false) ? 0 : $row['tax'];
                } elseif ($s->source_kind === 'reverse_charge_output') {
                    $nonCreditable = $row['output'] - $row['input'];
                    if ($state->reverse_charge_mode === CompanyTaxPack::GROSS) {
                        $net += $row['base'];
                        $tax += $row['output'];
                    } elseif ($nonCreditable !== 0) {
                        $net += $row['base'];
                        $tax += $nonCreditable;
                    }
                } elseif ($s->source_kind === 'reverse_charge_input') {
                    if ($state->reverse_charge_mode === CompanyTaxPack::GROSS) {
                        $net += $row['base'];
                        $tax += $row['input'];
                    }
                }
            }
            $isManual = $s->source_kind === 'manual';
            $amount = $isManual ? (int) ($manual[$s->section_key] ?? 0) : ($s->effect === 'none' ? $net : $tax);
            $payable += match ($s->effect) {
                'add' => $amount,
                'subtract' => -$amount,
                default => 0,
            };
            $out[] = [
                'key' => $s->section_key, 'group' => $s->group_key, 'label' => $s->label, 'note' => $s->note, 'effect' => $s->effect, 'manual' => $isManual,
                'net' => $isManual ? null : Json::money($net, $cur), 'tax' => $isManual ? null : Json::money($tax, $cur), 'amount' => Json::money($amount, $cur),
            ];
        }

        $unmapped = [];
        foreach ($rows as $key => $r) {
            $active = $r['rc'] ? ($r['base'] !== 0 || $r['output'] !== 0 || $r['input'] !== 0) : ($r['net'] !== 0 || $r['tax'] !== 0);
            [$side, $codeId] = explode(':', $key, 2);
            // The purchases row of a reverse-charge code is the supplier's net, reported through the reverse-charge sections.
            if ($side === 'purchases' && isset($used['reverse_charge:'.$codeId])) {
                continue;
            }
            if (! isset($used[$key]) && $active) {
                $unmapped[] = ['side' => $side, 'code' => $r['code'], 'net' => Json::money($r['rc'] ? $r['base'] : $r['net'], $cur), 'tax' => Json::money($r['rc'] ? $r['output'] - $r['input'] : $r['tax'], $cur)];
            }
        }

        $pack = $this->packs->find($state->pack_key);

        return [
            'key' => $state->pack_key, 'version' => $state->pack_version, 'name' => $pack['name'] ?? $state->pack_key,
            'reverse_charge_mode' => $state->reverse_charge_mode,
            'notes' => array_values(array_map(fn (array $n): string => (string) $n['text'], array_filter((array) ($pack['notes'] ?? []), fn (array $n): bool => $n['where'] === 'return'))),
            'sections' => $out,
            'net' => ['payable' => Json::money(max($payable, 0), $cur), 'carried_forward' => Json::money(max(-$payable, 0), $cur)],
            'unmapped' => $unmapped,
        ];
    }
}
