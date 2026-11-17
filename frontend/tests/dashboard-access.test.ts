import { describe, expect, it } from 'vitest';
import { ApiError } from '../src/api/errors';
import { DASHBOARD_PERMISSIONS, dashboardAccess, panelsToFetch, panelState, type DashboardPanel } from '../src/data/dashboard';

const holding = (...held: string[]) => (p: string) => held.includes(p);
const fetched = (...held: string[]) => panelsToFetch(dashboardAccess(holding(...held)), true).sort();

describe('dashboard panels follow the permission of their own source (plan 3c.26c)', () => {
  it('asks for nothing when the person holds nothing', () => {
    expect(fetched()).toEqual([]);
  });

  it('asks for nothing until the menu has arrived, whatever is held', () => {
    expect(panelsToFetch(dashboardAccess(() => true), false)).toEqual([]);
  });

  it('opens the report panels on the report right alone', () => {
    expect(fetched('reports.view')).toEqual(['ap', 'ar', 'cash', 'profit', 'trend']);
  });

  it('opens each other panel only on its own right', () => {
    expect(fetched('banking.view')).toEqual(['banks']);
    expect(fetched('journals.approve')).toEqual(['approvals']);
    expect(fetched('audit.view')).toEqual(['activity']);
    expect(fetched('accounts.view')).toEqual(['chart']);
    expect(fetched('invoices.view')).toEqual(['overdueInvoices', 'postedInvoice']);
    expect(fetched('bills.view')).toEqual(['overdueBills', 'postedBill']);
  });

  it('needs both the report and the budget right for the budget note', () => {
    expect(fetched('budgets.view')).not.toContain('budget');
    expect(fetched('reports.view')).not.toContain('budget');
    expect(fetched('reports.view', 'budgets.view')).toContain('budget');
  });

  it('opens everything for a person holding every right', () => {
    const all = Object.values(DASHBOARD_PERMISSIONS).flat();
    expect(fetched(...all)).toEqual((Object.keys(DASHBOARD_PERMISSIONS) as DashboardPanel[]).sort());
  });
});

describe('panelState', () => {
  const idle = { isPending: true, isError: false, error: null };

  it('hides a panel the person may not open, even though its query never ran', () => {
    expect(panelState(idle, false)).toBe('hidden');
    expect(panelState(idle, true)).toBe('loading');
  });

  it('still hides a panel whose source answers 403, and shows other failures', () => {
    const failed = (status: number) => ({ isPending: false, isError: true, error: new ApiError({ status, code: 'x', title: 't' } as never) });
    expect(panelState(failed(403))).toBe('hidden');
    expect(panelState(failed(500))).toBe('error');
  });
});
