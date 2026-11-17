import { describe, expect, it } from 'vitest';
import { monthPnlFromReport, trendFromReport } from '@/api/live/dashboardExtras';

describe('trendFromReport (income vs expenses by fiscal period, plan 3c.26c)', () => {
  const m = (amount: number) => ({ amount, currency: 'USD' });
  const row = (name: string, net: number, revenue = 0) => ({
    period_id: `id-${name}`, name, from: '2026-01-01', to: '2026-01-28',
    revenue: m(revenue), other_income: m(0), cost_of_sales: m(0), operating_expenses: m(revenue - net), other_expenses: m(0), net_profit: m(net),
  });

  it('labels each bar with the period name and keeps the server order and amounts', () => {
    const bars = trendFromReport({ currency: 'USD', periods: [row('P11', 100, 300), row('P12', -50, 100)] });
    expect(bars?.map((b) => b.label)).toEqual(['P11', 'P12']);
    expect(bars?.[0].pnl).toEqual({ currency: 'USD', revenue: 300, otherIncome: 0, costOfSales: 0, operatingExpenses: 200, otherExpenses: 0, netProfit: 100 });
    expect(bars?.[1].pnl.netProfit).toBe(-50);
  });

  it('returns an empty list for a company with no periods yet', () => {
    expect(trendFromReport({ currency: 'USD', periods: [] })).toEqual([]);
  });

  it('returns null rather than guess when the shape is not the one it knows', () => {
    expect(trendFromReport(null)).toBeNull();
    expect(trendFromReport({ currency: 'USD' })).toBeNull();
    expect(trendFromReport({ currency: 'USD', periods: [{ name: 'P1' }] })).toBeNull();
  });
});

describe('monthPnlFromReport', () => {
  const total = (amount: number) => ({ amount: { amount, currency: 'USD' } });
  it('takes the server section totals and net profit as given', () => {
    const pnl = monthPnlFromReport({
      currency: 'USD',
      sections: [{ key: 'operating_revenue', total: total(300000) }, { key: 'operating_expense', total: total(120000) }],
      subtotals: { net_profit: total(180000) },
    });
    expect(pnl).toEqual({ currency: 'USD', revenue: 300000, otherIncome: 0, costOfSales: 0, operatingExpenses: 120000, otherExpenses: 0, netProfit: 180000 });
  });

  it('returns null when the report has no net profit', () => {
    expect(monthPnlFromReport({ currency: 'USD', sections: [], subtotals: {} })).toBeNull();
  });
});
