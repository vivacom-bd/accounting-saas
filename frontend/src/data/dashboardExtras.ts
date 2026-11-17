/** Extra dashboard panels (live mode): income and expense by fiscal period, cash and bank, recent activity. Each is its own query, so a 403 or a failure leaves only that panel out. */
import { useQuery } from '@tanstack/react-query';
import { fetchClosingCash, fetchPnlTrend, fetchRecentAudit } from '@/api/live/dashboardExtras';
import { toAuditRow } from '@/api/adapters/admin';
import type { Company } from '@/types';
import { useDashboardAccess, yearStartFor } from './dashboard';

const opts = { retry: false, staleTime: 30_000 } as const;
const PERIODS_SHOWN = 6;

/** The fiscal year start, asked for only by the cash figure, so a person without it never reads fiscal years. */
export function useYearStart(company: Company, today: string) {
  const { allowed, ready, canViewPeriods } = useDashboardAccess();
  return useQuery({
    queryKey: ['dashboard', company.id, 'year-start', today, canViewPeriods],
    queryFn: () => yearStartFor(company, today, canViewPeriods),
    enabled: ready && allowed.cash,
    ...opts,
  });
}

/** The bars: the company's last fiscal periods up to today, named as the company names them (a 4-4-5 calendar gives its own periods). */
export function usePeriodTrend(company: Company, today: string) {
  const { allowed, ready } = useDashboardAccess();
  return useQuery({
    queryKey: ['dashboard', company.id, 'pnl-trend', today],
    queryFn: () => fetchPnlTrend(company.id, today, PERIODS_SHOWN),
    enabled: ready && allowed.trend,
    ...opts,
  });
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
