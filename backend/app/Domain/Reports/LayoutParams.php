<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Shared\DomainRuleViolation;

/**
 * The parameters a saved layout may hold (plan 3c.23), and which of them each report reads. Pure PHP. A layout holds
 * parameters and never figures; unknown keys are dropped, and a key the report does not read is refused rather than saved
 * silently. Ids (period, account, contact, budget, dimensions) are checked when the layout runs, because they can disappear.
 */
final class LayoutParams
{
    /** Reports a layout can be saved for: the ones that share the report shell. */
    public const array REPORTS = [
        'trial-balance', 'profit-and-loss', 'balance-sheet', 'cash-flow', 'general-ledger', 'ar-aging', 'ap-aging',
        'customer-statement', 'supplier-statement', 'tax-return', 'budget-vs-actual',
    ];

    /** Period rules: the period is worked out on the day the report runs, in the company's calendar. */
    public const array RULES = ['this_month', 'last_month', 'this_year_to_date', 'last_year_to_date', 'today'];

    /** Reports that are "as of" a date; the others cover a range. */
    public const array AS_OF = ['trial-balance', 'balance-sheet', 'ar-aging', 'ap-aging'];

    /** Reports that read `basis`, `comparison`, dimensions, a breakdown and the empty-row switch. */
    private const array READS = [
        'basis' => ['profit-and-loss', 'cash-flow', 'budget-vs-actual'],
        'comparison' => ['profit-and-loss', 'balance-sheet'],
        'dimensions' => ['trial-balance', 'profit-and-loss', 'balance-sheet', 'general-ledger', 'budget-vs-actual'],
        'breakdown' => ['trial-balance', 'profit-and-loss'],
        'hide_zero' => ['trial-balance', 'profit-and-loss', 'balance-sheet'],
        'view' => ['trial-balance'],
        'account_id' => ['general-ledger'],
        'contact_id' => ['customer-statement', 'supplier-statement'],
        'budget_id' => ['budget-vs-actual'],
    ];

    private const array WHEN_KEYS = ['period_id', 'as_of', 'from', 'to'];

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the clean parameters
     */
    public static function clean(string $report, array $params): array
    {
        if (! in_array($report, self::REPORTS, true)) {
            throw DomainRuleViolation::single('report_unsupported', 'Layouts cannot be saved for this report.');
        }
        $out = [];
        foreach (array_keys(self::READS) as $key) {
            if (! array_key_exists($key, $params) || $params[$key] === null || $params[$key] === [] || $params[$key] === '') {
                continue;
            }
            if (! in_array($report, self::READS[$key], true)) {
                throw DomainRuleViolation::single('param_unsupported', 'This report does not use '.str_replace('_', ' ', $key).'.');
            }
            $out[$key] = $params[$key];
        }
        foreach (array_keys((array) ($out['dimensions'] ?? [])) as $dimensionId) {
            if (preg_match('/^[0-9a-f-]{36}$/', (string) $dimensionId) !== 1) {
                throw DomainRuleViolation::single('param_invalid', 'Dimensions are filtered by dimension id.');
            }
        }
        if (isset($out['hide_zero']) && $out['hide_zero'] === false) {
            unset($out['hide_zero']);
        }
        if (($out['comparison'] ?? 'none') === 'none') {
            unset($out['comparison']);
        }
        if (($out['basis'] ?? 'month') === 'month' && ! isset($params['rule'])) {
            // `month` is the default; keep it only when it says something (a rule keeps its own).
            unset($out['basis']);
        }

        $rule = $params['rule'] ?? null;
        if ($rule !== null && $rule !== '') {
            if (! in_array($rule, self::RULES, true)) {
                throw DomainRuleViolation::single('rule_invalid', 'Choose one of the period choices offered.');
            }
            $asOf = in_array($report, self::AS_OF, true) && ($out['view'] ?? null) !== 'movement';
            if ($rule === 'today' && ! $asOf) {
                throw DomainRuleViolation::single('rule_invalid', 'Today only fits a report as of a date.');
            }
            $out['rule'] = $rule;
            unset($out['basis']);
        } else {
            foreach (self::WHEN_KEYS as $key) {
                if (isset($params[$key]) && $params[$key] !== '') {
                    $out[$key] = (string) $params[$key];
                }
            }
        }

        return $out;
    }
}
