<?php

declare(strict_types=1);

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;

/** An average rate for a currency over a period, with the reason the default does not fit. */
final class RateOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'size:3'],
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            /** Presentation-currency units per 1 unit of `currency`, an exact decimal with at most 8 decimals. */
            'rate' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,8})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
