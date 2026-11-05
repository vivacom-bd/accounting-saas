<?php

declare(strict_types=1);

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;

/** A row of a subsidiary's ownership history: from the close of `effective_on` the parent holds `percent_bp`. */
final class OwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'percent_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
