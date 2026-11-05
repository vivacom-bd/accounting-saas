/** Live tax period calls (plan 3c.21a). The server builds the return, the snapshot and the difference; nothing here adds up money. */
import { http } from '../http';
import type { Intent } from '../idempotency';
import type { ApiMoney } from '../adapters/money';

export interface ApiTaxPeriod {
  id: string;
  authority: string;
  starts_on: string;
  ends_on: string;
  due_on: string | null;
  status: 'open' | 'filed';
  filed_at: string | null;
  filed_by: string | null;
  authority_reference: string | null;
  reopened_at: string | null;
  reopen_reason: string | null;
  manual_amounts: Record<string, number>;
  changed_since_filing?: boolean;
  version: number;
  can: { update: boolean; file: boolean; reopen: boolean };
  return?: Record<string, unknown>;
  filed_return?: Record<string, unknown> | null;
  changes_since_filing?: Array<{ key: string; label: string; filed: number; current: number; difference: number }>;
}

export type { ApiMoney };

const loose = <T,>(v: unknown) => v as T;

export async function fetchTaxPeriods(companyId: string): Promise<ApiTaxPeriod[]> {
  return loose<{ data: ApiTaxPeriod[] }>(await http.get('/v1/companies/{company}/tax-periods', { path: { company: companyId } })).data;
}

export async function fetchTaxPeriod(companyId: string, id: string): Promise<ApiTaxPeriod> {
  return loose<{ data: ApiTaxPeriod }>(await http.get('/v1/companies/{company}/tax-periods/{taxPeriod}', { path: { company: companyId, taxPeriod: id } })).data;
}

export async function generateTaxPeriods(companyId: string, body: { from: string; months: number; authority?: string }, intent?: Intent): Promise<number> {
  const res = loose<{ meta: { created: number } }>(await http.post('/v1/companies/{company}/tax-periods', { path: { company: companyId }, body: body as never, intent }));
  return res.meta.created;
}

export async function fileTaxPeriod(companyId: string, p: ApiTaxPeriod, reference: string, intent?: Intent): Promise<ApiTaxPeriod> {
  return loose<{ data: ApiTaxPeriod }>(await http.post('/v1/companies/{company}/tax-periods/{taxPeriod}/file', { path: { company: companyId, taxPeriod: p.id }, body: { version: p.version, reference } as never, intent })).data;
}

export async function reopenTaxPeriod(companyId: string, p: ApiTaxPeriod, reason: string, intent?: Intent): Promise<ApiTaxPeriod> {
  return loose<{ data: ApiTaxPeriod }>(await http.post('/v1/companies/{company}/tax-periods/{taxPeriod}/reopen', { path: { company: companyId, taxPeriod: p.id }, body: { version: p.version, reason }, intent })).data;
}

export async function saveTaxPeriodAmounts(companyId: string, p: ApiTaxPeriod, amounts: Record<string, number>, intent?: Intent): Promise<ApiTaxPeriod> {
  return loose<{ data: ApiTaxPeriod }>(await http.put('/v1/companies/{company}/tax-periods/{taxPeriod}/amounts', { path: { company: companyId, taxPeriod: p.id }, body: { version: p.version, amounts } as never, intent })).data;
}
