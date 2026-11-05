/**
 * Saved report layouts (3c.23): turning the screen's selection into the parameters a layout stores, and a layout's resolved
 * parameters (the period rule worked out by the server for today) back into a selection. Pure functions.
 */
import type { ReportSelection, When } from './report';
import type { ReportKind } from './reportSelection';

/** How the period is kept. `fixed` stores exactly what is on the screen; the others move with the calendar. */
export type PeriodRule = 'fixed' | 'this_month' | 'last_month' | 'this_year_to_date' | 'last_year_to_date' | 'today';

export interface RuleChoice {
  value: PeriodRule;
  label: string;
}

/** The period choices offered when saving: as-of reports end on a date, range reports cover a stretch. */
export function ruleChoices(kind: ReportKind): RuleChoice[] {
  if (kind === 'asof') {
    return [
      { value: 'fixed', label: 'Keep this date' },
      { value: 'today', label: 'Always today' },
      { value: 'this_month', label: 'End of this month' },
      { value: 'last_month', label: 'End of last month' },
    ];
  }
  return [
    { value: 'fixed', label: 'Keep this period' },
    { value: 'this_month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: 'this_year_to_date', label: 'This year to date' },
    { value: 'last_year_to_date', label: 'Year to date, to last month' },
  ];
}

export const RULE_LABELS: Record<string, string> = {
  this_month: 'This month',
  last_month: 'Last month',
  this_year_to_date: 'This year to date',
  last_year_to_date: 'Year to date, to last month',
  today: 'Always today',
};

/** Which of the optional parameters the report reads (the screen's REPORT_PARAMS). */
export interface ParamUse {
  basis: boolean;
  comparison: boolean;
  dims: boolean;
  hideZero: boolean;
  breakdown?: boolean;
}

export interface StoredParams {
  rule?: string;
  period_id?: string;
  as_of?: string;
  from?: string;
  to?: string;
  basis?: 'month' | 'ytd';
  view?: 'movement';
  comparison?: 'previous_period' | 'previous_year';
  hide_zero?: boolean;
  dimensions?: Record<string, string>;
  breakdown?: string;
  account_id?: string;
  contact_id?: string;
  budget_id?: string;
}

/** The parameters to store for the screen's selection, with the chosen period rule (or none: keep the period). */
export function paramsFromSelection(report: string, sel: ReportSelection, use: ParamUse, rule: PeriodRule): StoredParams {
  const out: StoredParams = {};
  if (rule !== 'fixed') out.rule = rule;
  else if (sel.when.kind === 'period') {
    out.period_id = sel.when.periodId;
    if (use.basis && sel.basis === 'ytd') out.basis = 'ytd';
  } else if (sel.when.kind === 'range') {
    if (sel.when.from) out.from = sel.when.from;
    out.to = sel.when.to;
  } else out.as_of = sel.when.date;
  if (use.comparison && sel.comparison !== 'none') out.comparison = sel.comparison;
  if (use.hideZero && sel.hideZero) out.hide_zero = true;
  if (use.dims) {
    const dims = Object.fromEntries(Object.entries(sel.dims).filter(([, v]) => v));
    if (Object.keys(dims).length > 0) out.dimensions = dims;
  }
  if (use.breakdown && sel.breakdown && sel.view !== 'movement') out.breakdown = sel.breakdown;
  if (report === 'trial-balance' && sel.view === 'movement') out.view = 'movement';
  if (report === 'general-ledger' && sel.accountId) out.account_id = sel.accountId;
  if ((report === 'customer-statement' || report === 'supplier-statement') && sel.contactId) out.contact_id = sel.contactId;
  if (report === 'budget-vs-actual' && sel.budgetId) out.budget_id = sel.budgetId;
  return out;
}

const text = (v: unknown): string | undefined => (typeof v === 'string' && v !== '' ? v : undefined);

/** A layout's resolved parameters as a selection, over `base` for anything the layout does not say. */
export function selectionFromResolved(resolved: Record<string, unknown>, kind: ReportKind, base: ReportSelection): ReportSelection {
  let when: When = base.when;
  const period = text(resolved.period_id);
  const asOf = text(resolved.as_of);
  const from = text(resolved.from);
  const to = text(resolved.to);
  if (period) when = { kind: 'period', periodId: period };
  else if (asOf) when = kind === 'asof' ? { kind: 'asof', date: asOf } : { kind: 'range', from: `${asOf.slice(0, 8)}01`, to: asOf };
  else if (to) when = kind === 'asof' ? { kind: 'asof', date: to } : { kind: 'range', from: from ?? '', to };

  const dims: Record<string, string> = {};
  const raw = resolved.dimensions;
  if (raw && typeof raw === 'object') {
    for (const [id, v] of Object.entries(raw as Record<string, unknown>)) {
      const value = Array.isArray(v) ? text(v[0]) : text(v);
      if (value) dims[id] = value;
    }
  }
  return {
    when,
    basis: resolved.basis === 'ytd' ? 'ytd' : 'month',
    comparison: resolved.comparison === 'previous_period' || resolved.comparison === 'previous_year' ? resolved.comparison : 'none',
    hideZero: resolved.hide_zero === true,
    dims,
    accountId: text(resolved.account_id),
    contactId: text(resolved.contact_id),
    budgetId: text(resolved.budget_id),
    breakdown: text(resolved.breakdown),
    view: resolved.view === 'movement' ? 'movement' : undefined,
  };
}

/** The period rule a stored layout carries, for showing it (`fixed` when it keeps one period). */
export function ruleOf(params: Record<string, unknown>): PeriodRule {
  const rule = params.rule;
  return rule === 'this_month' || rule === 'last_month' || rule === 'this_year_to_date' || rule === 'last_year_to_date' || rule === 'today' ? rule : 'fixed';
}
