<?php

declare(strict_types=1);

namespace App\Http\Requests\EInvoicing;

use App\Http\Requests\CompanyRequest;

/** PUT /einvoicing: `enabled`, the `adapter` key, the seller's `tax_id` and `branch`, and the `version` of the settings when they exist. */
final class EInvoicingRequest extends CompanyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'], 'adapter' => ['nullable', 'string', 'max:40'], 'tax_id' => ['nullable', 'string', 'max:40'],
            'branch' => ['nullable', 'string', 'max:40'], 'version' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function enabled(): bool
    {
        return (bool) $this->validated()['enabled'];
    }

    public function adapter(): ?string
    {
        $v = $this->validated()['adapter'] ?? null;

        return $v === null ? null : (string) $v;
    }

    public function taxId(): ?string
    {
        $v = $this->validated()['tax_id'] ?? null;

        return $v === null ? null : (string) $v;
    }

    public function branch(): ?string
    {
        $v = $this->validated()['branch'] ?? null;

        return $v === null ? null : (string) $v;
    }

    public function version(): ?int
    {
        $v = $this->validated()['version'] ?? null;

        return $v === null ? null : (int) $v;
    }
}
