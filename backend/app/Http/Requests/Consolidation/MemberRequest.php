<?php

declare(strict_types=1);

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add a subsidiary: its company, the acquisition date and the parent's share from then on (basis points). */
final class MemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'acquisition_date' => ['required', 'date_format:Y-m-d'],
            'percent_bp' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
