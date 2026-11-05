<?php

declare(strict_types=1);

namespace App\Actions\Tax;

use App\Actions\Ledger\SetupGuard;
use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Ledger\Account;
use App\Models\Organisation\Company;
use App\Models\Tax\CompanyTaxPack;
use App\Models\Tax\TaxCode;
use App\Models\Tax\TaxRate;
use App\Models\Tax\TaxReturnSection;
use App\Models\Tax\TaxReturnSectionCode;
use App\Services\Tax\CountryPacks;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/**
 * Applies a country pack to a company, at set-up or later (plan 3c.22). It only ADDS: a tax code, a return section or an account
 * that already exists (by code or key) is left exactly as it is, whatever the pack now says, and nothing is deleted. So applying the
 * same pack twice changes nothing, and applying a newer version brings in what is new. The company's own settings (authority, due
 * days, reverse-charge mode) are taken from the pack the first time only. Needs `tax.manage`, and `accounts.manage` when it would add
 * accounts. One audit event says what was created and what was kept.
 */
final class ApplyCountryPack
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
        private readonly SetupGuard $guard,
        private readonly CountryPacks $packs,
    ) {}

    /**
     * @return array{pack_key: string, pack_version: int, created: array<string, int>, kept: array<string, int>}
     */
    public function handle(Actor $actor, string $companyId, string $packKey): array
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $companyId, $packKey) {
            $this->guard->require($actor, 'tax.manage', $companyId);
            $pack = $this->packs->find($packKey) ?? throw DomainRuleViolation::single('country_pack_unknown', "There is no country pack {$packKey}.");
            $company = Company::query()->whereKey($companyId)->lock('FOR NO KEY UPDATE')->firstOrFail();

            $existing = CompanyTaxPack::query()->where('company_id', $company->id)->first();
            if ($existing !== null && $existing->pack_key !== $packKey) {
                throw DomainRuleViolation::single('country_pack_other_applied', "This company already uses the {$existing->pack_key} pack.");
            }

            $created = ['tax_codes' => 0, 'rates' => 0, 'sections' => 0, 'mappings' => 0, 'accounts' => 0];
            $kept = ['tax_codes' => 0, 'sections' => 0, 'accounts' => 0];

            $sources = [];
            foreach ((array) $pack['sources'] as $s) {
                $sources[(string) $s['id']] = (string) $s['label'];
            }

            /** @var array<string, string> $codeIds pack code → the company's tax code id */
            $codeIds = [];
            foreach ((array) $pack['tax_codes'] as $def) {
                $have = TaxCode::query()->where('company_id', $company->id)->where('code', $def['code'])->first();
                if ($have !== null) {
                    $codeIds[(string) $def['code']] = $have->id;
                    $kept['tax_codes']++;

                    continue;
                }
                $code = new TaxCode;
                $code->forceFill([
                    'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $company->id, 'code' => $def['code'],
                    'name' => $def['name'], 'kind' => $def['kind'], 'is_recoverable' => $def['recoverable'], 'is_active' => true,
                ])->save();
                $codeIds[(string) $def['code']] = $code->id;
                $created['tax_codes']++;
                foreach ((array) $def['rates'] as $r) {
                    (new TaxRate)->forceFill([
                        'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $company->id, 'tax_code_id' => $code->id,
                        'rate' => $r['rate'], 'effective_from' => $r['from'], 'effective_to' => $r['to'] ?? null,
                        'source' => $sources[$r['source']] ?? $r['source'], 'source_as_of' => $r['as_of'],
                    ])->save();
                    $created['rates']++;
                }
            }

            foreach ((array) $pack['return_sections'] as $i => $def) {
                if (TaxReturnSection::query()->where('company_id', $company->id)->where('section_key', $def['key'])->exists()) {
                    $kept['sections']++;

                    continue;
                }
                $section = new TaxReturnSection;
                $section->forceFill([
                    'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $company->id, 'section_key' => $def['key'],
                    'pack_key' => $packKey, 'group_key' => $def['group'], 'label' => $def['label'], 'note' => $def['note'] ?? null,
                    'sort' => ($i + 1) * 10, 'effect' => $def['effect'], 'source_kind' => $def['source'],
                ])->save();
                $created['sections']++;
                foreach ((array) $def['map'] as [$side, $packCode]) {
                    if (! isset($codeIds[$packCode])) {
                        continue;
                    }
                    (new TaxReturnSectionCode)->forceFill([
                        'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $company->id,
                        'section_id' => $section->id, 'tax_code_id' => $codeIds[$packCode], 'side' => $side,
                    ])->save();
                    $created['mappings']++;
                }
            }

            $this->addAccounts($actor, $company, (array) ($pack['chart_additions'] ?? []), $created, $kept);

            $now = $this->clock->now()->format('Y-m-d H:i:sP');
            if ($existing === null) {
                (new CompanyTaxPack)->forceFill([
                    'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $company->id, 'pack_key' => $packKey,
                    'pack_version' => $pack['version'], 'authority' => $pack['authority'],
                    'due_days_after_period' => $pack['filing']['due_days_after_period_end'] ?? null,
                    'reverse_charge_mode' => $pack['reverse_charge_mode'], 'applied_by' => $actor->id, 'applied_at' => $now, 'last_applied_at' => $now,
                ])->save();
            } elseif ($existing->pack_version !== $pack['version']) {
                $existing->forceFill(['pack_version' => $pack['version'], 'last_applied_at' => $now, 'lock_version' => $existing->lock_version + 1])->save();
            } else {
                $existing->forceFill(['last_applied_at' => $now])->save();
            }

            $audit->add('country_pack.applied', $company, context: ['pack' => $packKey, 'version' => $pack['version'], 'created' => $created, 'kept' => $kept]);

            return ['pack_key' => $packKey, 'pack_version' => (int) $pack['version'], 'created' => $created, 'kept' => $kept];
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $additions
     * @param  array<string, int>  $created
     * @param  array<string, int>  $kept
     */
    private function addAccounts(Actor $actor, Company $company, array $additions, array &$created, array &$kept): void
    {
        if ($additions === []) {
            return;
        }
        $eight = Account::query()->withTrashed()->where('company_id', $company->id)->whereRaw('length(code) = 8')->exists();
        $stamp = $this->clock->now()->format('Y-m-d H:i:sP');
        $guarded = false;
        foreach ($additions as $def) {
            $code = (string) ($eight ? $def['code8'] : $def['code4']);
            if (Account::query()->withTrashed()->where('company_id', $company->id)->where('code', $code)->exists()) {
                $kept['accounts']++;

                continue;
            }
            if (! $guarded) {
                $this->guard->require($actor, 'accounts.manage', $company->id);
                $guarded = true;
            }
            Account::query()->insert([
                'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $company->id, 'code' => $code, 'name' => $def['name'],
                'account_type' => $def['type'], 'account_subtype' => $def['subtype'], 'normal_balance' => $def['normal'], 'parent_id' => null,
                'currency_policy' => 'any', 'control_type' => 'tax', 'is_postable' => true, 'description' => $def['description'] ?? null,
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $created['accounts']++;
        }
    }
}
