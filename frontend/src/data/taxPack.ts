/** Country packs, live mode (plan 3c.22). */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import type { Intent } from '@/api/idempotency';
import * as api from '@/api/live/taxPack';
import { taxCodesKey } from './setup';
import { taxPeriodsKey } from './taxPeriods';
import { useRouteCompany } from './session';

export const taxPackKey = (companyId: string) => ['tax-pack', companyId] as const;

export function useTaxPack() {
  const company = useRouteCompany();
  return useQuery({ queryKey: taxPackKey(company.id), queryFn: () => api.fetchTaxPack(company.id), retry: false });
}

export function useTaxPackActions() {
  const company = useRouteCompany();
  const qc = useQueryClient();
  const done = async (p: Promise<api.TaxPackState>): Promise<api.TaxPackState> => {
    const r = await p;
    qc.setQueryData(taxPackKey(company.id), r);
    // The pack changes tax codes, the return layout and period defaults.
    await Promise.all([
      qc.invalidateQueries({ queryKey: taxCodesKey(company.id) }),
      qc.invalidateQueries({ queryKey: taxPeriodsKey(company.id) }),
      qc.invalidateQueries({ queryKey: ['reports', company.id, 'tax-return'] }),
    ]);
    return r;
  };
  return {
    apply: (packKey: string, intent: Intent) => done(api.applyTaxPack(company.id, packKey, intent)),
    saveSettings: (body: Parameters<typeof api.saveTaxPackSettings>[1], intent: Intent) => done(api.saveTaxPackSettings(company.id, body, intent)),
    saveSection: (section: string, codes: Array<{ tax_code_id: string; side: string }>, intent: Intent) => done(api.saveSectionCodes(company.id, section, codes, intent)),
  };
}
