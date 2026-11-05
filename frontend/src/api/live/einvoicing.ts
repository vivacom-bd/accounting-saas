/** Live e-invoicing calls (plan 3c.27). The server decides every status; nothing here sends anything to an authority. */
import { http } from '../http';
import type { Intent } from '../idempotency';

export type EInvoiceStatusName = 'not_required' | 'pending' | 'submitting' | 'accepted' | 'rejected' | 'failed_retryable' | 'unknown' | 'voided_by_credit';

export interface EInvoiceStatus {
  status: EInvoiceStatusName;
  enabled: boolean;
  attempts?: number;
  next_attempt_at?: string | null;
  dead_lettered?: boolean;
  last_error_code?: string | null;
  last_error_message?: string | null;
  authority_number?: string | null;
  authority_timestamp?: string | null;
  device_id?: string | null;
  can_retry: boolean;
}

export interface EInvoicingSettings {
  enabled: boolean;
  adapter: string | null;
  tax_id: string | null;
  branch: string | null;
  version: number | null;
  adapters: Array<{ key: string; label: string }>;
}

const loose = <T,>(v: unknown) => (v as { data: T }).data;

export async function fetchEInvoiceStatus(companyId: string, documentId: string): Promise<EInvoiceStatus> {
  return loose<EInvoiceStatus>(await http.get('/v1/companies/{company}/invoices/{document}/einvoice', { path: { company: companyId, document: documentId } }));
}

export async function retryEInvoice(companyId: string, documentId: string, intent?: Intent): Promise<void> {
  await http.post('/v1/companies/{company}/invoices/{document}/einvoice/retry', { path: { company: companyId, document: documentId }, intent });
}

export async function fetchEInvoicingSettings(companyId: string): Promise<EInvoicingSettings> {
  return loose<EInvoicingSettings>(await http.get('/v1/companies/{company}/einvoicing', { path: { company: companyId } }));
}

export async function saveEInvoicingSettings(
  companyId: string, body: { enabled: boolean; adapter: string | null; tax_id: string | null; branch: string | null; version: number | null }, intent?: Intent,
): Promise<EInvoicingSettings> {
  return loose<EInvoicingSettings>(await http.put('/v1/companies/{company}/einvoicing', { path: { company: companyId }, body: body as never, intent }));
}
