<?php

declare(strict_types=1);

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;

/** Member accounts and the group account each maps to. */
final class MappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array', 'min:1', 'max:1000'],
            'mappings.*.account_id' => ['required', 'uuid'],
            'mappings.*.group_account_id' => ['required', 'uuid'],
        ];
    }

    /** @return list<array{account_id: string, group_account_id: string}> */
    public function pairs(): array
    {
        $out = [];
        foreach ((array) $this->validated()['mappings'] as $m) {
            $out[] = ['account_id' => (string) $m['account_id'], 'group_account_id' => (string) $m['group_account_id']];
        }

        return $out;
    }
}
