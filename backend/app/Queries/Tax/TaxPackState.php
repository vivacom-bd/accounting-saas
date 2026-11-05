<?php

declare(strict_types=1);

namespace App\Queries\Tax;

use App\Models\Tax\CompanyTaxPack;
use App\Models\Tax\TaxCode;
use App\Models\Tax\TaxReturnSection;
use App\Services\Tax\CountryPacks;

/** What the Tax screen shows about country packs: the packs on offer, the one the company applied with its settings, the sections and their mapping, and the pack's tax depreciation classes. */
final class TaxPackState
{
    public function __construct(private readonly CountryPacks $packs) {}

    /** @return array<string, mixed> */
    public function __invoke(string $companyId): array
    {
        $available = array_values(array_map(fn (array $p): array => ['key' => $p['key'], 'version' => $p['version'], 'name' => $p['name'], 'country' => $p['country'] ?? null], $this->packs->all()));
        $state = CompanyTaxPack::query()->where('company_id', $companyId)->first();
        if ($state === null) {
            return ['available' => $available, 'applied' => null, 'sections' => [], 'depreciation_classes' => []];
        }
        $pack = $this->packs->find($state->pack_key) ?? [];
        $codes = TaxCode::query()->where('company_id', $companyId)->pluck('code', 'id')->all();
        $sections = TaxReturnSection::query()->where('company_id', $companyId)->with('codes')->orderBy('sort')->get();

        return [
            'available' => $available,
            'applied' => [
                'pack_key' => $state->pack_key, 'pack_version' => $state->pack_version, 'name' => $pack['name'] ?? $state->pack_key,
                'latest_version' => $pack['version'] ?? $state->pack_version, 'authority' => $state->authority,
                'due_days_after_period' => $state->due_days_after_period, 'reverse_charge_mode' => $state->reverse_charge_mode,
                'applied_at' => $state->applied_at->format('Y-m-d\TH:i:s\Z'), 'version' => $state->lock_version,
                'checked_on' => $pack['checked_on'] ?? null, 'sources' => $pack['sources'] ?? [], 'metadata_notes' => $pack['metadata_notes'] ?? [], 'notes' => $pack['notes'] ?? [],
            ],
            'sections' => $sections->map(fn (TaxReturnSection $s): array => [
                'key' => $s->section_key, 'group' => $s->group_key, 'label' => $s->label, 'note' => $s->note, 'effect' => $s->effect, 'source_kind' => $s->source_kind,
                'codes' => $s->codes->map(fn ($m): array => ['tax_code_id' => $m->tax_code_id, 'code' => $codes[$m->tax_code_id] ?? '', 'side' => $m->side])->sortBy(['side', 'code'])->values()->all(),
            ])->all(),
            'depreciation_classes' => $pack['depreciation_classes'] ?? [],
        ];
    }
}
