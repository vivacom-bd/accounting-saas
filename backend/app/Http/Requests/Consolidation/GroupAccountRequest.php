<?php

declare(strict_types=1);

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;

/** A group account: code, name, type and subtype (the subtype drives the balance sheet class). */
final class GroupAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:160'],
            'account_type' => ['required', 'string', 'in:asset,liability,equity,income,expense'],
            'account_subtype' => ['required', 'string', 'max:64'],
        ];
    }
}
