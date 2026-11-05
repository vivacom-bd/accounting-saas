/**
 * Live calls for fund transfers, write-offs, recoveries after write-off and provisioning (plan 3c.8 to 3c.12a). Transport only: the
 * server holds every figure. Mutating calls take the caller's Idempotency intent.
 */
import { http } from '../http';
import type { Intent } from '../idempotency';
import type { CursorPage } from '../cursor';
import type { ApiInvoicePreview } from '../adapters/invoice';
import type { ApiFundTransfer, ApiProvisionPreview, ApiProvisionRun, ApiRecovery, ApiWriteOff, TxFamily } from '../adapters/transactions';

const base = (company: string) => `/v1/companies/${company}`;
type Wrapped<T> = { data: T; warnings?: unknown };

const qs = (q: Record<string, unknown>): string => {
  const s = new URLSearchParams(Object.entries(q).filter(([, v]) => v !== undefined && v !== '' && v !== null).map(([k, v]) => [k, String(v)])).toString();
  return s ? `?${s}` : '';
};

export interface TxQuery {
  status?: string;
  write_off_id?: string;
  invoice_id?: string;
}

export const listTx = <T>(company: string, family: TxFamily, q: TxQuery, cursor?: string, limit = 25) =>
  http.send<CursorPage<T>>('GET', `${base(company)}/${family}${qs({ ...q, cursor, limit })}`);

export const getTx = async <T>(company: string, family: TxFamily, id: string): Promise<T> => (await http.send<Wrapped<T>>('GET', `${base(company)}/${family}/${id}`)).data;

export const createTx = async <T>(company: string, family: TxFamily, body: unknown, intent: Intent): Promise<T> =>
  (await http.send<Wrapped<T>>('POST', `${base(company)}/${family}`, body, intent)).data;

export const updateTx = async <T>(company: string, family: TxFamily, id: string, body: unknown, intent: Intent): Promise<T> =>
  (await http.send<Wrapped<T>>('PUT', `${base(company)}/${family}/${id}`, body, intent)).data;

/** The journal lines the server would post, and its verdict. Writes nothing. */
export const previewTx = async (company: string, family: Exclude<TxFamily, 'provision-runs'>, id: string, intent: Intent): Promise<ApiInvoicePreview> =>
  (await http.send<Wrapped<ApiInvoicePreview>>('POST', `${base(company)}/${family}/${id}/posting-preview`, {}, intent)).data;

export type TxVerb = 'submit' | 'approve' | 'return' | 'void' | 'reverse' | 'receive';

export const transitionTx = async <T>(company: string, family: TxFamily, id: string, verb: TxVerb, body: Record<string, unknown>, intent: Intent): Promise<T> =>
  (await http.send<Wrapped<T>>('POST', `${base(company)}/${family}/${id}/${verb}`, body, intent)).data;

export type { ApiFundTransfer, ApiWriteOff, ApiRecovery, ApiProvisionRun };

/* ---------------------------------------------------------------- provisioning */

export interface ApiProvisionRates {
  buckets: Array<{ bucket: string; label: string; rate_pct: string | null }>;
  configured: boolean;
  can?: { manage_rates: boolean };
}

export const getProvisionRates = async (company: string): Promise<ApiProvisionRates> => (await http.send<Wrapped<ApiProvisionRates>>('GET', `${base(company)}/provision-rates`)).data;

export const saveProvisionRates = async (company: string, body: unknown, intent: Intent): Promise<ApiProvisionRates> =>
  (await http.send<Wrapped<ApiProvisionRates>>('PUT', `${base(company)}/provision-rates`, body, intent)).data;

export const previewProvision = async (company: string, asOf: string, intent: Intent): Promise<ApiProvisionPreview> =>
  (await http.send<Wrapped<ApiProvisionPreview>>('POST', `${base(company)}/provision-runs/preview`, { as_of: asOf }, intent)).data;

/* ---------------------------------------------------------------- invoice side */

/** Submits a customer invoice and returns the answer's warnings next to the document (credit limit in `warn` mode). */
export const submitInvoice = (company: string, id: string, body: Record<string, unknown>, intent: Intent) =>
  http.send<Wrapped<unknown>>('POST', `${base(company)}/invoices/${id}/submit`, body, intent);
