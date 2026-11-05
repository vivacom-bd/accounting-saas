<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Actions\Reports\Inputs\LayoutInput;
use App\Domain\Reports\LayoutParams;
use App\Http\Requests\CompanyRequest;
use Illuminate\Validation\Rule;

/** Save (or change) a report layout: a name, who sees it, and the report's parameters, as the report's query string takes them. */
final class ReportLayoutRequest extends CompanyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'report' => ['required', 'string', Rule::in(LayoutParams::REPORTS)],
            'name' => ['required', 'string', 'max:80'],
            'visibility' => ['required', 'string', Rule::in(['private', 'company'])],
            'is_default' => ['sometimes', 'boolean'],
            'params' => ['required', 'array'],
            /** Move with the calendar: this_month, last_month, this_year_to_date, last_year_to_date, or today (as-of reports). Leave out for a fixed period. */
            'params.rule' => ['nullable', 'string', Rule::in(LayoutParams::RULES)],
            'params.period_id' => ['nullable', 'uuid'],
            'params.as_of' => ['nullable', 'date_format:Y-m-d'],
            'params.from' => ['nullable', 'date_format:Y-m-d'],
            'params.to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:params.from'],
            'params.basis' => ['nullable', 'string', Rule::in(['month', 'ytd'])],
            'params.view' => ['nullable', 'string', Rule::in(['balances', 'movement'])],
            'params.comparison' => ['nullable', 'string', Rule::in(['none', 'previous_period', 'previous_year'])],
            'params.hide_zero' => ['nullable', 'boolean'],
            /** Dimension id → value id. */
            'params.dimensions' => ['nullable', 'array', 'max:8'],
            'params.dimensions.*' => ['string', 'regex:/^[0-9a-f-]{36}$/'],
            'params.breakdown' => ['nullable', 'uuid'],
            'params.account_id' => ['nullable', 'uuid'],
            'params.contact_id' => ['nullable', 'uuid'],
            'params.budget_id' => ['nullable', 'uuid'],
            /** Send what you loaded: a stale value answers 409. */
            'version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function toInput(?string $layoutId = null): LayoutInput
    {
        $v = $this->validated();

        return new LayoutInput(
            $this->company()->id, (string) $v['report'], (string) $v['name'], (string) $v['visibility'],
            (array) ($v['params'] ?? []), (bool) ($v['is_default'] ?? false), $layoutId, isset($v['version']) ? (int) $v['version'] : null,
        );
    }
}
