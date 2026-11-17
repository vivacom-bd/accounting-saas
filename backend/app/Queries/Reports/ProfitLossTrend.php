<?php

declare(strict_types=1);

namespace App\Queries\Reports;

use App\Http\Support\Json;
use App\Models\Organisation\Company;

/**
 * The dashboard's income and expense bars (plan 3c.26c): the company's last N regular fiscal periods that have started by a date,
 * oldest first, each with the section totals and net profit of that period's own profit and loss. Periods are the company's, so a 4-4-5
 * or fiscal-year-shifted calendar gives its own bars, labelled by period name; the adjustment period is never a bar (its entries already
 * count in the regular period that ends the same day). Every figure is the cached profit and loss of the period, so a bar and the
 * report it drills to cannot differ.
 */
final class ProfitLossTrend
{
    public function __construct(private readonly PeriodCalendar $calendar, private readonly RunReport $report) {}

    /** @return array<string, mixed> */
    public function __invoke(Company $company, string $asOf, int $count): array
    {
        $regular = array_values(array_filter(
            $this->calendar->periods($company->id),
            fn (CalendarPeriod $p): bool => $p->isRegular() && $p->startsOn <= $asOf,
        ));
        $chosen = array_slice($regular, -$count);

        $currency = (string) $company->base_currency;
        $out = [];
        foreach ($chosen as $p) {
            $pnl = ($this->report)('profit-and-loss', $company, ['period_id' => $p->id, 'basis' => 'month']);
            $currency = (string) $pnl['currency'];
            $sections = [];
            foreach ((array) $pnl['sections'] as $s) {
                $sections[(string) $s['key']] = (int) $s['total']['amount']['amount'];
            }
            $out[] = [
                'period_id' => $p->id,
                'name' => $p->name,
                'from' => $p->startsOn,
                'to' => $p->endsOn,
                'revenue' => Json::money($sections['operating_revenue'] ?? 0, $currency),
                'other_income' => Json::money($sections['other_income'] ?? 0, $currency),
                'cost_of_sales' => Json::money($sections['direct_costs'] ?? 0, $currency),
                'operating_expenses' => Json::money($sections['operating_expense'] ?? 0, $currency),
                'other_expenses' => Json::money($sections['other_expense'] ?? 0, $currency),
                'net_profit' => Json::money((int) $pnl['subtotals']['net_profit']['amount']['amount'], $currency),
            ];
        }

        return ['currency' => $currency, 'periods' => $out];
    }
}
