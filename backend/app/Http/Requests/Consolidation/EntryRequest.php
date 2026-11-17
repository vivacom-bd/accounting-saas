<?php

declare(strict_types=1);

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;

/** A manual group entry: signed `net` per line (debit positive, credit negative) in the presentation currency. */
final class EntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['nullable', 'string', 'in:manual,unrealised_profit'],
            'memo' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.member_id' => ['nullable', 'uuid'],
            /** Debit positive, credit negative, minor units of the presentation currency. */
            'lines.*.net' => ['required', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return list<array{account_id: string, member_id: string|null, net: int, description: string|null}> */
    public function lines(): array
    {
        $out = [];
        foreach ((array) $this->validated()['lines'] as $l) {
            $out[] = ['account_id' => (string) $l['account_id'], 'member_id' => isset($l['member_id']) ? (string) $l['member_id'] : null, 'net' => (int) $l['net'], 'description' => isset($l['description']) ? (string) $l['description'] : null];
        }

        return $out;
    }
}
