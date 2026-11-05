/** Live country pack calls (plan 3c.22). The server applies, maps and lays out the return; nothing here computes tax. */
import { http } from '../http';
import type { Intent } from '../idempotency';

export interface PackSource { id: string; label: string; secondary: boolean }
export interface PackNote { where: string; text: string }
export interface PackDepClass { key: string; label: string; method: string; first_year_bp: number | null; annual_bp: number; source: string; secondary: boolean; note?: string }
export interface PackSectionState {
  key: string;
  group: 'output' | 'input' | 'adjustments' | 'credits';
  label: string;
  note: string | null;
  effect: 'add' | 'subtract' | 'none';
  source_kind: 'codes' | 'reverse_charge_output' | 'reverse_charge_input' | 'manual';
  codes: Array<{ tax_code_id: string; code: string; side: 'sales' | 'purchases' | 'reverse_charge' }>;
}
export interface TaxPackState {
  available: Array<{ key: string; version: number; name: string; country: string | null }>;
  applied: null | {
    pack_key: string; pack_version: number; latest_version: number; name: string; authority: string; due_days_after_period: number | null;
    reverse_charge_mode: 'gross' | 'net_non_creditable'; applied_at: string; version: number; checked_on: string | null;
    sources: PackSource[]; metadata_notes: string[]; notes: PackNote[];
  };
  sections: PackSectionState[];
  depreciation_classes: PackDepClass[];
  result?: { created: Record<string, number>; kept: Record<string, number> };
}

const loose = <T,>(v: unknown) => (v as { data: T }).data;

export async function fetchTaxPack(companyId: string): Promise<TaxPackState> {
  return loose<TaxPackState>(await http.get('/v1/companies/{company}/tax-pack', { path: { company: companyId } }));
}

export async function applyTaxPack(companyId: string, packKey: string, intent?: Intent): Promise<TaxPackState> {
  return loose<TaxPackState>(await http.post('/v1/companies/{company}/tax-pack/apply', { path: { company: companyId }, body: { pack_key: packKey } as never, intent }));
}

export async function saveTaxPackSettings(
  companyId: string, body: { version: number; authority: string; due_days_after_period: number | null; reverse_charge_mode: string }, intent?: Intent,
): Promise<TaxPackState> {
  return loose<TaxPackState>(await http.put('/v1/companies/{company}/tax-pack/settings', { path: { company: companyId }, body: body as never, intent }));
}

export async function saveSectionCodes(companyId: string, section: string, codes: Array<{ tax_code_id: string; side: string }>, intent?: Intent): Promise<TaxPackState> {
  return loose<TaxPackState>(await http.put('/v1/companies/{company}/tax-pack/sections/{section}/codes', { path: { company: companyId, section }, body: { codes } as never, intent }));
}
