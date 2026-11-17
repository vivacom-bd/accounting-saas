/**
 * Dashboard data, live mode. Each figure is its own query so a panel the person may not see (403) or one
 * that fails does not take the page down. Query keys start with the resource, then the company id.
 *
 * Every panel is asked for only when the person holds the permission its source endpoint checks (plan 3c.26c);
 * `DASHBOARD_PERMISSIONS` is the one list of which, mirrored by the server's policies and tested on both sides.
 */
import { useQuery } from '@tanstack/react-query';
import { ApiError } from '@/api/errors';
import { fiscalYearStart, localDateISO } from '@/api/adapters/onboarding';
import {
  fetchAging, fetchApprovalCount, fetchBankRows, fetchFiscalYearStart, fetchNetProfit, fetchOverdueCount, hasPosted,
} from '@/api/live/dashboard';
import { hasAccounts } from '@/api/live/setup';
import type { Company } from '@/types';
import { useAccess } from './menu';

export type PanelState = 'loading' | 'hidden' | 'error' | 'ready';

/** 403 means "not for your role": the panel is left out rather than shown broken. */
export function panelState(q: { isPending: boolean; isError: boolean; error: unknown }, allowed = true): PanelState {
  if (!allowed) return 'hidden';
  if (q.isPending) return 'loading';
  if (q.isError) return q.error instanceof ApiError && q.error.status === 403 ? 'hidden' : 'error';
  return 'ready';
}

/** The permissions each dashboard source needs, all of them (the budget note needs both). */
export const DASHBOARD_PERMISSIONS = {
  ar: ['reports.view'],
  ap: ['reports.view'],
  profit: ['reports.view'],
  cash: ['reports.view'],
  trend: ['reports.view'],
  budget: ['reports.view', 'budgets.view'],
  banks: ['banking.view'],
  approvals: ['journals.approve'],
  overdueInvoices: ['invoices.view'],
  overdueBills: ['bills.view'],
  chart: ['accounts.view'],
  postedInvoice: ['invoices.view'],
  postedBill: ['bills.view'],
  activity: ['audit.view'],
} as const;

export type DashboardPanel = keyof typeof DASHBOARD_PERMISSIONS;
export type DashboardAccess = Record<DashboardPanel, boolean>;

/** Which panels this person holds the right to; a pure function so both modes and the tests share it. */
export function dashboardAccess(can: (permission: string) => boolean): DashboardAccess {
  const out = {} as DashboardAccess;
  for (const panel of Object.keys(DASHBOARD_PERMISSIONS) as DashboardPanel[]) out[panel] = DASHBOARD_PERMISSIONS[panel].every((p) => can(p));
  return out;
}

/** The panels whose source is called: none until the menu arrives (live), then exactly those the person may open. */
export function panelsToFetch(allowed: DashboardAccess, ready: boolean): DashboardPanel[] {
  return ready ? (Object.keys(allowed) as DashboardPanel[]).filter((p) => allowed[p]) : [];
}

/** Access for the page: until the menu arrives (live) nothing is asked for, but panels read as loading rather than hidden. */
export function useDashboardAccess(): { allowed: DashboardAccess; ready: boolean; canViewPeriods: boolean } {
  const access = useAccess();
  return { allowed: dashboardAccess(access.can), ready: access.ready, canViewPeriods: access.can('periods.view') };
}

const opts = { retry: false, staleTime: 30_000 } as const;

/** The first day of the fiscal year: from the company's years when the person may read them, else from the company's start month. */
export async function yearStartFor(company: Company, today: string, canViewPeriods: boolean): Promise<string> {
  return (canViewPeriods ? await fetchFiscalYearStart(company.id, today) : null) ?? fiscalYearStart(company.fiscal_year_start_month, today);
}

export function useLiveDashboard(company: Company) {
  const id = company.id;
  const { allowed, ready, canViewPeriods } = useDashboardAccess();
  const fetching = panelsToFetch(allowed, ready);
  const on = (panel: DashboardPanel) => fetching.includes(panel);
  const today = localDateISO();
  const ar = useQuery({ queryKey: ['dashboard', id, 'ar', today], queryFn: () => fetchAging(id, 'ar', today), enabled: on('ar'), ...opts });
  const ap = useQuery({ queryKey: ['dashboard', id, 'ap', today], queryFn: () => fetchAging(id, 'ap', today), enabled: on('ap'), ...opts });
  const profit = useQuery({
    queryKey: ['dashboard', id, 'profit', today],
    queryFn: async () => fetchNetProfit(id, await yearStartFor(company, today, canViewPeriods), today),
    enabled: on('profit'),
    ...opts,
  });
  const banks = useQuery({ queryKey: ['dashboard', id, 'banks'], queryFn: () => fetchBankRows(id), enabled: on('banks'), ...opts });
  const approvals = useQuery({ queryKey: ['dashboard', id, 'approvals'], queryFn: () => fetchApprovalCount(id), enabled: on('approvals'), ...opts });
  const overdueInvoices = useQuery({ queryKey: ['dashboard', id, 'overdue-invoices', today], queryFn: () => fetchOverdueCount(id, 'invoices'), enabled: on('overdueInvoices'), ...opts });
  const overdueBills = useQuery({ queryKey: ['dashboard', id, 'overdue-bills', today], queryFn: () => fetchOverdueCount(id, 'bills'), enabled: on('overdueBills'), ...opts });
  const chart = useQuery({ queryKey: ['dashboard', id, 'has-accounts'], queryFn: () => hasAccounts(id), enabled: on('chart'), ...opts });
  const postedInvoice = useQuery({ queryKey: ['dashboard', id, 'posted-invoice'], queryFn: () => hasPosted(id, 'invoices'), enabled: on('postedInvoice'), ...opts });
  const postedBill = useQuery({ queryKey: ['dashboard', id, 'posted-bill'], queryFn: () => hasPosted(id, 'bills'), enabled: on('postedBill'), ...opts });
  // A panel is "allowed" while the menu is still loading, so it reads as loading rather than flashing away.
  const may = (panel: DashboardPanel) => !ready || allowed[panel];
  return {
    today, canViewPeriods, ready, allowed, may,
    mayApprove: may('approvals'),
    ar, ap, profit, banks, approvals, overdueInvoices, overdueBills, chart, postedInvoice, postedBill,
  };
}
