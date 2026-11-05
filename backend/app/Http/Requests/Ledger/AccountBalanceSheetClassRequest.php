<?php

declare(strict_types=1);

namespace App\Http\Requests\Ledger;

use App\Http\Requests\CompanyRequest;

/** PUT /accounts/{account}/balance-sheet-class: `{"class": "non_current" | "current" | null, "version": 3}`; null returns to the default rules. */
final class AccountBalanceSheetClassRequest extends CompanyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'class' => ['present', 'nullable', 'string', 'in:current,non_current'],
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
