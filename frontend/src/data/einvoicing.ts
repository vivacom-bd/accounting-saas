/** E-invoicing, live mode (plan 3c.27). */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import type { Intent } from '@/api/idempotency';
import * as api from '@/api/live/einvoicing';
import { useRouteCompany } from './session';

export const eInvoiceKey = (companyId: string, documentId: string) => ['einvoice', companyId, documentId] as const;
export const eInvoicingSettingsKey = (companyId: string) => ['einvoicing-settings', companyId] as const;

/** The status of a posted sales document. A failure to read it is never shown as a status: the caller shows nothing. */
export function useEInvoiceStatus(documentId: string, enabled: boolean) {
  const company = useRouteCompany();
  return useQuery({ queryKey: eInvoiceKey(company.id, documentId), queryFn: () => api.fetchEInvoiceStatus(company.id, documentId), enabled, retry: false, refetchInterval: (q) => (['pending', 'submitting'].includes(q.state.data?.status ?? '') ? 5000 : false) });
}

export function useEInvoiceRetry(documentId: string) {
  const company = useRouteCompany();
  const qc = useQueryClient();
  return async (intent: Intent) => {
    await api.retryEInvoice(company.id, documentId, intent);
    await qc.invalidateQueries({ queryKey: eInvoiceKey(company.id, documentId) });
  };
}

export function useEInvoicingSettings() {
  const company = useRouteCompany();
  return useQuery({ queryKey: eInvoicingSettingsKey(company.id), queryFn: () => api.fetchEInvoicingSettings(company.id), retry: false });
}

export function useSaveEInvoicingSettings() {
  const company = useRouteCompany();
  const qc = useQueryClient();
  return async (body: Parameters<typeof api.saveEInvoicingSettings>[1], intent: Intent) => {
    const r = await api.saveEInvoicingSettings(company.id, body, intent);
    qc.setQueryData(eInvoicingSettingsKey(company.id), r);
    return r;
  };
}
