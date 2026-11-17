<?php

declare(strict_types=1);

namespace App\Http\Requests\Ledger;

use App\Http\Requests\CompanyRequest;

/** PUT /accounts/{account}/monetary-class: `{"class": "monetary" | "non_monetary" | null, "version": 3}`; null returns to the subtype default. */
final class AccountMonetaryClassRequest extends CompanyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'class' => ['present', 'nullable', 'string', 'in:monetary,non_monetary'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    public function class(): ?string
    {
        $c = $this->validated()['class'];

        return $c === null ? null : (string) $c;
    }

    public function version(): int
    {
        return (int) $this->validated()['version'];
    }
}
