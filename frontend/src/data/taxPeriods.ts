/** Tax periods, live mode (plan 3c.21a). Keys start with the resource, then the company id. */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import type { Intent } from '@/api/idempotency';
import * as api from '@/api/live/taxPeriods';
import { useRouteCompany } from './session';

export const taxPeriodsKey = (companyId: string) => ['tax-periods', companyId] as const;

export function useTaxPeriods() {
  const company = useRouteCompany();
  return useQuery({ queryKey: taxPeriodsKey(company.id), queryFn: () => api.fetchTaxPeriods(company.id), retry: false });
}

export function useTaxPeriod(id: string | null) {
  const company = useRouteCompany();
  return useQuery({ queryKey: [...taxPeriodsKey(company.id), id], queryFn: () => api.fetchTaxPeriod(company.id, id ?? ''), enabled: id !== null, retry: false });
}

export function useTaxPeriodActions() {
  const company = useRouteCompany();
  const qc = useQueryClient();
  const done = async <T,>(p: Promise<T>): Promise<T> => {
    const r = await p;
    await qc.invalidateQueries({ queryKey: taxPeriodsKey(company.id) });
    return r;
  };
  return {
    generate: (body: { from: string; months: number; authority?: string }, intent: Intent) => done(api.generateTaxPeriods(company.id, body, intent)),
    file: (p: api.ApiTaxPeriod, reference: string, intent: Intent) => done(api.fileTaxPeriod(company.id, p, reference, intent)),
    reopen: (p: api.ApiTaxPeriod, reason: string, intent: Intent) => done(api.reopenTaxPeriod(company.id, p, reason, intent)),
    saveAmounts: (p: api.ApiTaxPeriod, amounts: Record<string, number>, intent: Intent) => done(api.saveTaxPeriodAmounts(company.id, p, amounts, intent)),
  };
}
