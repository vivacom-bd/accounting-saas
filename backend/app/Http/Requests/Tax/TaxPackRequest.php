<?php

declare(strict_types=1);

namespace App\Http\Requests\Tax;

use App\Http\Requests\CompanyRequest;

/**
 * One request class for the country pack writes: apply (`pack_key`), settings (`version`, `authority`, `due_days_after_period`,
 * `reverse_charge_mode`) and a section's tax codes (`codes`: list of `{tax_code_id, side}`). The route's method decides the rules.
 */
final class TaxPackRequest extends CompanyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'apply' => ['pack_key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/']],
            'settings' => [
                'version' => ['required', 'integer', 'min:0'], 'authority' => ['required', 'string', 'max:60'],
                'due_days_after_period' => ['nullable', 'integer', 'between:0,120'], 'reverse_charge_mode' => ['required', 'string', 'in:gross,net_non_creditable'],
            ],
            default => [
                'codes' => ['present', 'array', 'max:60'], 'codes.*.tax_code_id' => ['required', 'uuid', $this->inCompany('tax_codes')],
                'codes.*.side' => ['required', 'string', 'in:sales,purchases,reverse_charge'],
            ],
        };
    }

    public function packKey(): string
    {
        return (string) $this->validated()['pack_key'];
    }

    public function version(): int
    {
        return (int) $this->validated()['version'];
    }

    public function authority(): string
    {
        return trim((string) $this->validated()['authority']);
    }

    public function dueDays(): ?int
    {
        $d = $this->validated()['due_days_after_period'] ?? null;

        return $d === null ? null : (int) $d;
    }

    public function reverseChargeMode(): string
    {
        return (string) $this->validated()['reverse_charge_mode'];
    }

    /** @return list<array{tax_code_id: string, side: string}> */
    public function codes(): array
    {
        $out = [];
        foreach ((array) $this->validated()['codes'] as $c) {
            $out[] = ['tax_code_id' => (string) $c['tax_code_id'], 'side' => (string) $c['side']];
        }

        return $out;
    }
}
