<?php

declare(strict_types=1);

namespace App\Actions\Tax;

use App\Actions\Ledger\SetupGuard;
use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Tax\CompanyTaxPack;
use App\Models\Tax\TaxCode;
use App\Models\Tax\TaxReturnSection;
use App\Models\Tax\TaxReturnSectionCode;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/**
 * A company's own edits to what a country pack gave it (`tax.manage`, plan 3c.22): the pack settings and which tax codes feed which
 * return section. Both are audited with before and after. Changing a mapping changes how the return is laid out, never the ledger.
 */
final class ManageTaxPack
{
    public function __construct(private readonly UnitOfWork $uow, private readonly IdGenerator $ids, private readonly SetupGuard $guard) {}

    public function updateSettings(Actor $actor, string $companyId, int $version, string $authority, ?int $dueDays, string $reverseChargeMode): CompanyTaxPack
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $companyId, $version, $authority, $dueDays, $reverseChargeMode) {
            $this->guard->require($actor, 'tax.manage', $companyId);
            $pack = $locks->document(CompanyTaxPack::query()->where('company_id', $companyId)->firstOrFail(), $version);
            if (! in_array($reverseChargeMode, [CompanyTaxPack::GROSS, CompanyTaxPack::NET_NON_CREDITABLE], true)) {
                throw DomainRuleViolation::single('reverse_charge_mode_unknown', 'The reverse charge reporting mode is gross or net_non_creditable.');
            }
            $before = $pack->attributesToArray();
            $pack->forceFill(['authority' => trim($authority), 'due_days_after_period' => $dueDays, 'reverse_charge_mode' => $reverseChargeMode, 'lock_version' => $pack->lock_version + 1])->save();
            $audit->add('country_pack.settings_changed', $pack, before: $before, after: $pack);

            return $pack;
        });
    }

    /**
     * Replaces the tax codes feeding one section.
     *
     * @param  list<array{tax_code_id: string, side: string}>  $codes
     */
    public function setSectionCodes(Actor $actor, string $companyId, string $sectionKey, array $codes): TaxReturnSection
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $companyId, $sectionKey, $codes) {
            $this->guard->require($actor, 'tax.manage', $companyId);
            $section = TaxReturnSection::query()->where('company_id', $companyId)->where('section_key', $sectionKey)->lock('FOR UPDATE')->first()
                ?? throw DomainRuleViolation::single('return_section_unknown', 'There is no such section in this company’s tax return.');

            $sides = match ($section->source_kind) {
                'codes' => ['sales', 'purchases'],
                'reverse_charge_output', 'reverse_charge_input' => ['reverse_charge'],
                default => [],
            };
            $wanted = [];
            foreach ($codes as $c) {
                if (! in_array($c['side'], $sides, true)) {
                    throw DomainRuleViolation::single('return_section_side_invalid', 'This section takes '.($sides === [] ? 'no tax codes, its figure is entered by hand' : implode(' or ', $sides).' tax codes').'.');
                }
                if (! TaxCode::query()->where('company_id', $companyId)->whereKey($c['tax_code_id'])->exists()) {
                    throw DomainRuleViolation::single('tax_code_unknown', 'Choose tax codes of this company.');
                }
                $wanted[$c['side'].':'.$c['tax_code_id']] = $c;
            }

            $current = TaxReturnSectionCode::query()->where('section_id', $section->id)->get();
            $before = $current->map(fn (TaxReturnSectionCode $m): string => $m->side.':'.$m->tax_code_id)->sort()->values()->all();
            foreach ($current as $m) {
                if (! isset($wanted[$m->side.':'.$m->tax_code_id])) {
                    $m->delete();
                }
            }
            $have = array_flip($before);
            foreach ($wanted as $key => $c) {
                if (! isset($have[$key])) {
                    (new TaxReturnSectionCode)->forceFill([
                        'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $companyId,
                        'section_id' => $section->id, 'tax_code_id' => $c['tax_code_id'], 'side' => $c['side'],
                    ])->save();
                }
            }
            $after = array_keys($wanted);
            sort($after);
            if ($before !== $after) {
                $audit->add('tax_return_section.mapping_changed', $section, before: ['codes' => $before], after: ['codes' => $after]);
            }

            return $section;
        });
    }
}
