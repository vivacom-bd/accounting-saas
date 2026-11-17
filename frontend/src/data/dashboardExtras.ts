/** Extra dashboard panels (live mode): monthly income and expense, cash and bank, recent activity. Each is its own query, so a 403 or a failure leaves only that panel out. */
import { useQueries, useQuery } from '@tanstack/react-query';
import { fetchClosingCash, fetchMonthPnl, fetchRecentAudit } from '@/api/live/dashboardExtras';
import { toAuditRow } from '@/api/adapters/admin';
import type { Company } from '@/types';
import { useDashboardAccess, yearStartFor } from './dashboard';

const opts = { retry: false, staleTime: 30_000 } as const;
const MONTHS_SHOWN = 6;

export interface MonthRange {
  label: string;
  from: string;
  to: string;
}

/** The last six calendar months up to `today`, but not before the fiscal year starts (the report is per fiscal year). Dates are plain strings. */
export function monthRanges(today: string, yearStart: string): MonthRange[] {
  const [y, m] = today.split('-').map(Number);
  const out: MonthRange[] = [];
  for (let i = MONTHS_SHOWN - 1; i >= 0; i--) {
    const d = new Date(Date.UTC(y, m - 1 - i, 1));
    const from = d.toISOString().slice(0, 10);
    const last = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).toISOString().slice(0, 10);
    const to = last < today ? last : today;
    if (to < yearStart) continue;
    out.push({ label: d.toLocaleString('en-US', { month: 'short', timeZone: 'UTC' }), from: from < yearStart ? yearStart : from, to });
  }
  return out;
}

/** The fiscal year start, asked for only by panels that need it (the monthly chart and cash), so a person with neither never reads fiscal years. */
export function useYearStart(company: Company, today: string) {
  const { allowed, ready, canViewPeriods } = useDashboardAccess();
  return useQuery({
    queryKey: ['dashboard', company.id, 'year-start', today, canViewPeriods],
    queryFn: () => yearStartFor(company, today, canViewPeriods),
    enabled: ready && (allowed.trend || allowed.cash),
    ...opts,
  });
}

export function useMonthlyPnl(company: Company, today: string, yearStart: string | undefined) {
  const { allowed, ready } = useDashboardAccess();
  const ranges = yearStart && ready && allowed.trend ? monthRanges(today, yearStart) : [];
  const results = useQueries({
    queries: ranges.map((r) => ({ queryKey: ['dashboard', company.id, 'pnl-month', r.from, r.to], queryFn: () => fetchMonthPnl(company.id, r.from, r.to), ...opts })),
  });
  return { ranges, results, pending: !yearStart || results.some((r) => r.isPending), error: results.find((r) => r.isError)?.error ?? null };
}

export function useClosingCash(company: Company, today: string, yearStart: string | undefined) {
  const { allowed, ready } = useDashboardAccess();
  return useQuery({
    queryKey: ['dashboard', company.id, 'closing-cash', today],
    queryFn: () => fetchClosingCash(company.id, yearStart as string, today),
    enabled: ready && allowed.cash && Boolean(yearStart),
    ...opts,
  });
}

export function useRecentActivity(company: Company) {
  // The audit trail needs `audit.view`; without it the call is a guaranteed 403 (plan 3c.24).
  const { allowed, ready } = useDashboardAccess();
  return useQuery({
    enabled: ready && allowed.activity,
    queryKey: ['dashboard', company.id, 'recent-audit'],
    queryFn: async () => (await fetchRecentAudit(company.id, 8)).map(toAuditRow),
    ...opts,
  });
}
