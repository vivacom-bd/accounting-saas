<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Actions\Reports\Inputs\ScheduleInput;
use App\Domain\Reports\ScheduleRule;
use App\Http\Requests\CompanyRequest;
use Illuminate\Validation\Rule;

/** Set or change a layout's scheduled email delivery, in friendly terms: how often, which day, which hour, which format, to whom. */
final class ReportScheduleRequest extends CompanyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'frequency' => ['required', 'string', Rule::in(ScheduleRule::FREQUENCIES)],
            /** Weekly: 1 (Monday) to 7 (Sunday). */
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            /** Monthly and quarterly (January, April, July, October): a day from 1 to 28, or `last_day`. */
            'month_day' => ['nullable', 'integer', 'between:1,28'],
            'last_day' => ['sometimes', 'boolean'],
            /** The hour of the day, 0 to 23, in the company's timezone. */
            'hour' => ['required', 'integer', 'between:0,23'],
            'format' => ['required', 'string', Rule::in(['pdf', 'xlsx', 'csv'])],
            /** People of the company, by user id. */
            'recipient_user_ids' => ['present', 'array', 'max:10'],
            'recipient_user_ids.*' => ['uuid'],
            /** Other email addresses. */
            'recipient_emails' => ['present', 'array', 'max:10'],
            'recipient_emails.*' => ['string', 'max:254'],
            'active' => ['sometimes', 'boolean'],
            'version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function toInput(string $layoutId): ScheduleInput
    {
        $v = $this->validated();

        return new ScheduleInput(
            $this->company()->id, $layoutId, (string) $v['frequency'],
            isset($v['weekday']) ? (int) $v['weekday'] : null, isset($v['month_day']) ? (int) $v['month_day'] : null, (bool) ($v['last_day'] ?? false),
            (int) $v['hour'], (string) $v['format'], array_values(array_map('strval', (array) $v['recipient_user_ids'])), array_values(array_map('strval', (array) $v['recipient_emails'])),
            (bool) ($v['active'] ?? true), isset($v['version']) ? (int) $v['version'] : null,
        );
    }
}
