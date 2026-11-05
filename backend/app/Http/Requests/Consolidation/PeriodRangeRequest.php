<?php

declare(strict_types=1);

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;

/** The period a check or a run covers: results from `from` to `to`, balances at `to`. */
final class PeriodRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function from(): string
    {
        return (string) $this->validated()['from'];
    }

    public function to(): string
    {
        return (string) $this->validated()['to'];
    }
}
