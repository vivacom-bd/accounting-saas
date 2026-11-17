<?php

declare(strict_types=1);

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening translated equity of a subsidiary held before the group's first run: as of the last consolidation done
 * elsewhere, the equity per group equity account at historical rates and the translation reserve, in minor units of the
 * presentation currency, net (debit positive, credit negative).
 */
final class OpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'as_of' => ['required', 'date_format:Y-m-d'],
            /** The translation reserve at `as_of`, net: a credit (a gain so far) is negative. */
            'translation_reserve' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
            'equity' => ['present', 'array', 'max:200'],
            'equity.*.group_account_id' => ['required', 'uuid', 'distinct'],
            /** Net, minor units of the presentation currency; equity is normally a credit, so negative. */
            'equity.*.amount' => ['required', 'integer'],
            /** The opening's `version`, when changing an existing one. */
            'version' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, int> */
    public function equity(): array
    {
        $out = [];
        foreach ((array) $this->validated()['equity'] as $row) {
            $out[(string) $row['group_account_id']] = (int) $row['amount'];
        }

        return $out;
    }
}
