/** Live billing operations (plan Task 13): invoices mirror, refunds/credit notes, tax and revenue exports, payouts. */
import { adminHttp, ADMIN_API_PREFIX } from '../../adminHttp';
import { API_BASE_URL } from '../../config';
import { createIntent, type Intent } from '../../idempotency';

export interface Invoice {
  id: string;
  tenant_id: string;
  stripe_id: string | null;
  source: 'stripe' | 'offline';
  number: string | null;
  status: string;
  currency: string;
  subtotal: number;
  tax: number;
  total: number;
  amount_paid: number;
  amount_refunded: number;
  due_at: string | null;
  paid_at: string | null;
  pdf_url: string | null;
  dunning_stage: number;
  created_at: string | null;
}
export interface InvoicePage {
  data: Invoice[];
  meta: { next_cursor: string | null; prev_cursor: string | null; limit: number };
}
export interface TaxRow {
  country: string | null;
  currency: string;
  month: string;
  net: number;
  tax: number;
  gross: number;
}
export interface RecognitionRow {
  invoice_id: string;
  number: string | null;
  tenant_id: string;
  currency: string;
  description: string;
  plan_code: string | null;
  recognised: number;
}
export interface PayoutRow {
  stripe_id: string;
  amount: number;
  currency: string;
  arrival_date: string | null;
  status: string;
}
export interface ApprovalRef {
  id: string;
  kind: string;
  status: string;
}

const data = <T>(r: { data: T }): T => r.data;

export interface InvoiceFilters {
  status?: string;
  tenant?: string;
  currency?: string;
  from?: string;
  to?: string;
}

export function invoiceQuery(f: InvoiceFilters, cursor?: string): string {
  const q = new URLSearchParams();
  for (const k of ['status', 'tenant', 'currency', 'from', 'to'] as const) if (f[k]) q.set(k, f[k] as string);
  if (cursor) q.set('cursor', cursor);
  const s = q.toString();
  return s ? `?${s}` : '';
}

export const listInvoices = (f: InvoiceFilters, cursor?: string) =>
  adminHttp.send<InvoicePage>('GET', `/billing/invoices${invoiceQuery(f, cursor)}`);

export const refundInvoice = (id: string, amount: number, reason: string, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: ApprovalRef }>('POST', `/billing/invoices/${encodeURIComponent(id)}/refund`, { amount, reason }, intent).then(data);
export const creditNoteInvoice = (id: string, amount: number, reason: string, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: ApprovalRef }>('POST', `/billing/invoices/${encodeURIComponent(id)}/credit-note`, { amount, reason }, intent).then(data);

export interface OfflineInvoiceBody {
  tenant_id: string;
  number: string;
  currency: string;
  subtotal: number;
  tax: number;
  due_at: string;
  period_start?: string;
  period_end?: string;
}
export const recordOfflineInvoice = (body: OfflineInvoiceBody, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: { id: string } }>('POST', '/billing/invoices', body, intent).then(data);
export const markInvoicePaid = (id: string, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: { id: string; status: string } }>('POST', `/billing/invoices/${encodeURIComponent(id)}/mark-paid`, undefined, intent).then(data);

export const getTaxReport = (from: string, to: string) =>
  adminHttp.send<{ data: TaxRow[] }>('GET', `/billing/tax-report?from=${from}&to=${to}`).then(data);
export const getRevenueRecognition = (month: string) =>
  adminHttp.send<{ data: RecognitionRow[] }>('GET', `/billing/revenue-recognition?month=${month}`).then(data);
export const getPayouts = (from: string, to: string) =>
  adminHttp.send<{ data: PayoutRow[] }>('GET', `/billing/payouts?from=${from}&to=${to}`).then(data);

/** Download a CSV from the admin API (cookie-authenticated GET). */
export async function downloadCsv(path: string, filename: string): Promise<void> {
  const res = await fetch(`${API_BASE_URL}${ADMIN_API_PREFIX}${path}`, { credentials: 'include', headers: { Accept: 'text/csv' } });
  if (!res.ok) throw new Error('The export could not be downloaded.');
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}
