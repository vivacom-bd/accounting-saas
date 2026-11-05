<?php

declare(strict_types=1);

namespace App\Queries\Reports;

use App\Domain\Reports\LayoutParams;
use App\Models\Organisation\Company;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Turns a saved layout's parameters into the input a report takes (the same keys as the query string), working out a period
 * rule against the company's calendar on the day given (the company's own date). Reads only.
 */
final class LayoutResolver
{
    public function __construct(private readonly PeriodCalendar $calendar) {}

    /**
     * @param  array<string, mixed>  $params  the layout's stored parameters
     * @return array<string, mixed> report input; no `rule` key
     */
    public function input(Company $company, string $report, array $params, DateTimeImmutable $today): array
    {
        $rule = $params['rule'] ?? null;
        unset($params['rule']);
        if (! is_string($rule)) {
            return $params;
        }
        $date = $today->format('Y-m-d');
        if ($rule === 'today') {
            return ['as_of' => $date] + $params;
        }
        $current = $this->calendar->regularContaining($company->id, $date)
            ?? throw $this->none("The company's calendar has no period for {$date}.");
        $period = in_array($rule, ['last_month', 'last_year_to_date'], true)
            ? ($this->calendar->previousRegular($company->id, $current) ?? throw $this->none('There is no earlier period to report on yet.'))
            : $current;
        $ytd = in_array($rule, ['this_year_to_date', 'last_year_to_date'], true);

        // Reports built on a period take it as it is; a plain date range (the ledger) is spelled out.
        if (in_array($report, LayoutParams::AS_OF, true) && ($params['view'] ?? null) !== 'movement') {
            return ['period_id' => $period->id] + $params;
        }
        if ($report === 'general-ledger') {
            return ['from' => $ytd ? $period->yearStartsOn : $period->startsOn, 'to' => $period->endsOn] + $params;
        }

        return ['period_id' => $period->id, 'basis' => $ytd ? 'ytd' : 'month'] + $params;
    }

    private function none(string $message): ValidationException
    {
        return ValidationException::withMessages(['rule' => [$message]]);
    }
}
