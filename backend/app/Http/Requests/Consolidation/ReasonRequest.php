<?php

declare(strict_types=1);

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;

/** Why a run or entry is reversed or voided. */
final class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    public function reason(): string
    {
        return (string) $this->validated()['reason'];
    }
}
