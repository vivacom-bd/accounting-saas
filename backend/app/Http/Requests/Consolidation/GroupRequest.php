<?php

declare(strict_types=1);

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create a group (name, currency, parent) or update it (name, tolerance, version). */
final class GroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->route('group') === null;

        return [
            'name' => ['required', 'string', 'max:160'],
            'presentation_currency' => [$creating ? 'required' : 'prohibited', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'parent_company_id' => [$creating ? 'required' : 'prohibited', 'uuid', Rule::exists('companies', 'id')],
            /** Largest intercompany difference, in minor units of the presentation currency, that matches without a second person's acceptance. */
            'ic_tolerance' => ['nullable', 'integer', 'min:0'],
            'version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:0'],
        ];
    }
}
