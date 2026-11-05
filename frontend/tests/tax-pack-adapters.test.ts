import { describe, expect, it } from 'vitest';
import { toRateRequest, toLiveTaxCode } from '@/api/adapters/tax';
import { parseTaxReturn } from '@/api/adapters/report';

describe('tax rate request (3c.22)', () => {
  it('sends a source and a checked-on date only when given, and never goes through a float', () => {
    expect(toRateRequest('0.5', '2026-01-01', 3)).toEqual({ rate: '0.005', effective_from: '2026-01-01', version: 3 });
    expect(toRateRequest('16', '2026-01-01', 3, ' Finance Act 2026 ', '2026-11-05')).toEqual({
      rate: '0.16', effective_from: '2026-01-01', version: 3, source: 'Finance Act 2026', source_as_of: '2026-11-05',
    });
    expect(toRateRequest('abc', '2026-01-01', 3)).toBeNull();
  });

  it('keeps the source of each rate for display', () => {
    const code = toLiveTaxCode({
      id: 'c1', code: 'KE-VAT16', name: 'VAT 16%', kind: 'vat', rate: '0.160000', collect_account_id: null, pay_account_id: null, is_recoverable: true, is_active: true, version: 0,
      rates: [{ id: 'r1', rate: '0.160000', effective_from: '2020-01-01', effective_to: null, source: 'VAT Act', source_as_of: '2026-11-05' }],
      can: { update: true, deactivate: true },
    } as never);
    expect(code.rates[0]).toMatchObject({ source: 'VAT Act', source_as_of: '2026-11-05' });
  });
});

describe('tax return with a pack (3c.22)', () => {
  const money = (n: number) => ({ amount: n, currency: 'KES' });
  const base = {
    report: 'tax_return', currency: 'KES', from: '2026-03-01', to: '2026-03-31', generated_at: '2026-04-01T00:00:00Z',
    sales: { tax_type: 'output', rows: [], totals: { net: money(0), tax: money(0) } },
    purchases: { tax_type: 'input', rows: [], totals: { net: money(0), tax: money(0) } },
    net_tax_payable: money(0),
  };

  it('has no pack when the server sent none, so the screen stays as it was', () => {
    expect(parseTaxReturn(base).pack).toBeNull();
  });

  it('passes the pack through untouched', () => {
    const pack = { key: 'ke', sections: [], net: { payable: money(1), carried_forward: money(0) }, unmapped: [], notes: [] };
    expect(parseTaxReturn({ ...base, pack }).pack).toEqual(pack);
  });
});
