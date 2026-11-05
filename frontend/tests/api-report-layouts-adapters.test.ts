import { describe, expect, it } from 'vitest';
import { paramsFromSelection, ruleChoices, ruleOf, selectionFromResolved } from '../src/api/adapters/reportLayouts';
import type { ReportSelection } from '../src/api/adapters/report';

const base: ReportSelection = { when: { kind: 'period', periodId: 'p-oct' }, basis: 'ytd', comparison: 'previous_year', hideZero: true, dims: { d1: 'v1', d2: '' }, breakdown: 'd1' };
const USE = { basis: true, comparison: true, dims: true, hideZero: true, breakdown: true };

describe('saved report layouts', () => {
  it('stores a fixed period exactly, and only what the report reads', () => {
    expect(paramsFromSelection('profit-and-loss', base, USE, 'fixed')).toEqual({ period_id: 'p-oct', basis: 'ytd', comparison: 'previous_year', hide_zero: true, dimensions: { d1: 'v1' }, breakdown: 'd1' });
    expect(paramsFromSelection('cash-flow', base, { basis: true, comparison: false, dims: false, hideZero: false }, 'fixed')).toEqual({ period_id: 'p-oct', basis: 'ytd' });
  });

  it('stores a period rule instead of the period', () => {
    expect(paramsFromSelection('profit-and-loss', base, USE, 'last_month')).toMatchObject({ rule: 'last_month' });
    expect(paramsFromSelection('profit-and-loss', base, USE, 'last_month')).not.toHaveProperty('period_id');
  });

  it('keeps the account, contact, budget and the movement view only for the reports that use them', () => {
    const sel: ReportSelection = { ...base, accountId: 'a1', contactId: 'c1', budgetId: 'b1', view: 'movement', when: { kind: 'range', from: '2026-01-01', to: '2026-03-31' } };
    expect(paramsFromSelection('general-ledger', sel, USE, 'fixed')).toMatchObject({ account_id: 'a1', from: '2026-01-01', to: '2026-03-31' });
    expect(paramsFromSelection('customer-statement', sel, USE, 'fixed')).toMatchObject({ contact_id: 'c1' });
    expect(paramsFromSelection('budget-vs-actual', sel, USE, 'fixed')).toMatchObject({ budget_id: 'b1' });
    const tb = paramsFromSelection('trial-balance', sel, USE, 'fixed');
    expect(tb).toMatchObject({ view: 'movement' });
    expect(tb).not.toHaveProperty('breakdown');
    expect(tb).not.toHaveProperty('account_id');
  });

  it('turns what the server worked out for today back into a selection', () => {
    const sel = selectionFromResolved({ period_id: 'p-oct', basis: 'ytd', comparison: 'previous_period', hide_zero: true, dimensions: { d1: 'v1' }, breakdown: 'd9' }, 'range', { ...base, accountId: 'keep-not' });
    expect(sel).toEqual({ when: { kind: 'period', periodId: 'p-oct' }, basis: 'ytd', comparison: 'previous_period', hideZero: true, dims: { d1: 'v1' }, accountId: undefined, contactId: undefined, budgetId: undefined, breakdown: 'd9', view: undefined });
    expect(selectionFromResolved({ as_of: '2026-11-05' }, 'asof', base).when).toEqual({ kind: 'asof', date: '2026-11-05' });
    expect(selectionFromResolved({ from: '2026-02-01', to: '2026-02-28' }, 'range', base).when).toEqual({ kind: 'range', from: '2026-02-01', to: '2026-02-28' });
  });

  it('offers period choices that fit the kind of report', () => {
    expect(ruleChoices('asof').map((c) => c.value)).toEqual(['fixed', 'today', 'this_month', 'last_month']);
    expect(ruleChoices('range').map((c) => c.value)).toContain('this_year_to_date');
    expect(ruleOf({ rule: 'last_month' })).toBe('last_month');
    expect(ruleOf({ period_id: 'x' })).toBe('fixed');
  });
});
