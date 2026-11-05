import { describe, it, expect } from 'vitest';
import { ApiError } from '../src/api/errors';
import {
  BUCKETS, amountReasonProblems, blankRates, blankTransferForm, fxDifferenceText, isCreditLimitRefusal, isPercent, isRate, legStateText, minor,
  readWarnings, receiveProblems, ratesProblems, toBucketRows, toRatesRequest, toReceiveRequest, toRecoveryRequest, toTransferForm, toTransferRequest,
  toTxCan, toWriteOffRequest, transferFormProblems, txErrorText, txStatusWord, type ApiFundTransfer,
} from '../src/api/adapters/transactions';
import { parseTaxReturn } from '../src/api/adapters/report';
import { toApprovalItem } from '../src/api/adapters/journal';
import { toDocCan } from '../src/api/adapters/invoice';
import { isLiveRoute } from '../src/data/liveRoutes';

const err = (code: string, extra: Record<string, unknown> = {}) => new ApiError({ status: 422, code, detail: `server says ${code}`, requestId: null, ...extra });

describe('fund transfer form', () => {
  const base = { ...blankTransferForm('2026-03-10'), from: 'a', to: 'b', fromAmount: 100000 };

  it('needs the arriving amount only when the currencies differ', () => {
    expect(transferFormProblems(base, { from: 'USD', to: 'USD' })).toEqual({});
    expect(transferFormProblems(base, { from: 'EUR', to: 'USD' }).toAmount).toMatch(/arrives in USD/);
    expect(transferFormProblems({ ...base, toAmount: 109500 }, { from: 'EUR', to: 'USD' })).toEqual({});
  });
  it('refuses the same account twice, a fee with no account and a bad rate', () => {
    expect(transferFormProblems({ ...base, to: 'a' }, {}).to).toMatch(/two different/);
    expect(transferFormProblems({ ...base, fee: 500 }, {}).feeAccountId).toBeDefined();
    expect(transferFormProblems({ ...base, sendRate: '1,1' }, {}).sendRate).toBeDefined();
    expect(isRate('1.10000000')).toBe(true);
    expect(isRate('0')).toBe(false);
    expect(isRate('1.123456789')).toBe(false);
  });
  it('builds the body from integers and strings, leaving empty optional fields out', () => {
    const body = toTransferRequest({ ...base, toAmount: 109500, fee: 500, feeAccountId: 'fee', sameDay: true, sendRate: ' 1.1 ' }, true);
    expect(body).toMatchObject({ from_bank_account_id: 'a', to_bank_account_id: 'b', from_amount_fc: 100000, to_amount_fc: 109500, fee_fc: 500, fee_account_id: 'fee', settle_same_day: true, send_rate: '1.1' });
    expect(body).not.toHaveProperty('receive_rate');
    expect(body).not.toHaveProperty('version');
    const plain = toTransferRequest(base, false, 3);
    expect(plain).not.toHaveProperty('to_amount_fc');
    expect(plain).not.toHaveProperty('fee_fc');
    expect(plain.version).toBe(3);
  });
  it('reads a saved transfer back into the form', () => {
    const t = {
      from_bank_account_id: 'a', to_bank_account_id: 'b', send_date: '2026-03-10', from_amount: { amount: 100000, currency: 'EUR' }, to_amount: { amount: 109500, currency: 'USD' },
      fee: { amount: 0, currency: 'EUR' }, fee_account_id: null, settle_same_day: false, reference: null, memo: 'm',
    } as unknown as ApiFundTransfer;
    expect(toTransferForm(t)).toMatchObject({ from: 'a', fromAmount: 100000, toAmount: 109500, fee: 0, memo: 'm', reference: '' });
  });
});

describe('receiving a transfer', () => {
  it('will not take a date before the send date or a bad rate', () => {
    expect(receiveProblems({ receivedOn: '2026-03-09', amount: 0, rate: '' }, '2026-03-10').receivedOn).toMatch(/before it was sent/);
    expect(receiveProblems({ receivedOn: '2026-03-12', amount: 0, rate: 'abc' }, '2026-03-10').rate).toBeDefined();
    expect(receiveProblems({ receivedOn: '2026-03-12', amount: 0, rate: '' }, '2026-03-10')).toEqual({});
  });
  it('sends the amount and rate only when stated', () => {
    expect(toReceiveRequest({ receivedOn: '2026-03-12', amount: 0, rate: '' }, 4)).toEqual({ received_on: '2026-03-12', version: 4 });
    expect(toReceiveRequest({ receivedOn: '2026-03-12', amount: 22000, rate: '1.12' }, 4)).toEqual({ received_on: '2026-03-12', to_amount_fc: 22000, receive_rate: '1.12', version: 4 });
  });
  it('names a gain or a loss from the server sign without recomputing', () => {
    expect(fxDifferenceText({ amount: 500, currency: 'USD' })).toEqual({ kind: 'gain', amount: 500 });
    expect(fxDifferenceText({ amount: -500, currency: 'USD' })).toEqual({ kind: 'loss', amount: 500 });
    expect(fxDifferenceText({ amount: 0, currency: 'USD' }).kind).toBe('none');
    expect(fxDifferenceText(null).kind).toBe('none');
  });
  it('says plainly where the money is', () => {
    expect(legStateText({ status: 'posted', leg_state: 'in_transit', received_on: null })).toMatchObject({ tone: 'warn' });
    expect(legStateText({ status: 'posted', leg_state: 'in_transit', received_on: null }).label).toMatch(/In transit/);
    expect(legStateText({ status: 'posted', leg_state: 'completed', received_on: '2026-03-12' }).tone).toBe('ok');
    expect(legStateText({ status: 'draft', leg_state: 'not_sent', received_on: null }).label).toMatch(/Nothing has left/);
    expect(legStateText({ status: 'reversed', leg_state: 'completed', received_on: null }).label).toMatch(/Reversed/);
  });
});

describe('write-off and recovery forms', () => {
  const form = { date: '2026-04-20', amount: 5500, reason: 'Customer insolvent', reference: '' };
  it('checks the amount against the figure the server sent, and wants a reason', () => {
    expect(amountReasonProblems(form, 5500, 'write-off')).toEqual({});
    expect(amountReasonProblems({ ...form, amount: 5501 }, 5500, 'write-off').amount).toMatch(/more than the invoice/);
    expect(amountReasonProblems({ ...form, amount: 2001 }, 2000, 'recovery').amount).toMatch(/not yet recovered/);
    expect(amountReasonProblems({ ...form, reason: ' ' }, 5500, 'write-off').reason).toBeDefined();
    expect(amountReasonProblems({ ...form, amount: 0 }, 5500, 'write-off').amount).toBeDefined();
  });
  it('builds the requests', () => {
    expect(toWriteOffRequest('inv', form)).toEqual({ invoice_id: 'inv', write_off_date: '2026-04-20', amount_fc: 5500, reason: 'Customer insolvent', reference: null });
    expect(toRecoveryRequest('wo', { ...form, amount: 2000, reference: ' R1 ' }, 2)).toEqual({
      write_off_id: 'wo', recovery_date: '2026-04-20', amount_fc: 2000, reason: 'Customer insolvent', reference: 'R1', version: 2,
    });
  });
});

describe('provisioning rates', () => {
  it('accepts a percentage from 0 to 100 with up to four decimals', () => {
    for (const ok of ['0', '2.5', '100', '100.0000', '12.3456']) expect(isPercent(ok)).toBe(true);
    for (const bad of ['', '101', '-1', '1.23456', '5%', 'abc']) expect(isPercent(bad)).toBe(false);
  });
  it('wants a rate for every bucket and sends them as text', () => {
    expect(Object.keys(ratesProblems(blankRates()))).toHaveLength(BUCKETS.length);
    const f = { current: '2', days_1_30: '5', days_31_60: '10', days_61_90: '25', days_over_90: '50' };
    expect(ratesProblems(f)).toEqual({});
    expect(toRatesRequest({ ...f, current: ' 2.5 ' })).toEqual({ rates: { ...f, current: '2.5' } });
  });
  it('reads the bucket rows the server sends', () => {
    const rows = toBucketRows([{ bucket: 'days_over_90', label: 'x', balance: { amount: 100000, currency: 'USD' }, rate_pct: '50.0000', amount: { amount: 50000, currency: 'USD' } }]);
    expect(rows).toEqual([{ bucket: 'days_over_90', label: 'x', balance: 100000, rate_pct: '50.0000', amount: 50000, currency: 'USD' }]);
  });
});

describe('errors, statuses and hints', () => {
  it('speaks plainly for the codes it knows and passes the server message otherwise', () => {
    expect(txErrorText(err('provision_run_stale'), '')).toMatch(/out of date/);
    expect(txErrorText(err('recovery_amount_used'), '')).toMatch(/Reverse that receipt/);
    expect(txErrorText(err('something_new'), 'fallback')).toBe('server says something_new');
    expect(txErrorText(new Error('x'), 'fallback')).toBe('fallback');
  });
  it('knows a credit limit refusal by code or violation', () => {
    expect(isCreditLimitRefusal(err('credit_limit_exceeded'))).toBe(true);
    expect(isCreditLimitRefusal(err('period_closed', { violations: [{ code: 'credit_limit_exceeded', message: 'm' }] }))).toBe(true);
    expect(isCreditLimitRefusal(err('period_closed'))).toBe(false);
  });
  it('keeps only well-formed warnings', () => {
    expect(readWarnings({ warnings: [{ code: 'credit_limit_exceeded', message: 'Over' }, { code: 1 }, null] })).toEqual([{ code: 'credit_limit_exceeded', message: 'Over' }]);
    expect(readWarnings({})).toEqual([]);
  });
  it('maps the can hints and money', () => {
    expect(toTxCan({ approve: true, recover: true, receive: 1 })).toMatchObject({ approve: true, recover: true, receive: false, reverse: false });
    expect(toDocCan({ write_off: true, override_credit_limit: true })).toMatchObject({ write_off: true, override_credit_limit: true, override_match: false });
    expect(minor({ amount: 12, currency: 'USD' })).toBe(12);
    expect(minor(null)).toBe(0);
    expect(txStatusWord('pending_approval')).toBe('Waiting for approval');
  });
});

describe('tax return reverse-charge section', () => {
  const money = (amount: number) => ({ amount, currency: 'USD' });
  const side = (type: string) => ({ tax_type: type, rows: [], totals: { net: money(0), tax: money(0) } });
  const body = (reverse: unknown) => ({
    report: 'tax_return', currency: 'USD', from: '2026-03-01', to: '2026-03-31', sales: side('output'), purchases: side('input'), reverse_charge: reverse, net_tax_payable: money(1000),
  });
  it('reads rows and totals as the server sent them', () => {
    const tr = parseTaxReturn(body({
      rows: [{ tax_code: { id: 'x', code: 'RC10', name: 'Reverse charge 10%', kind: 'reverse_charge' }, recoverable: true, base: money(10000), output: money(1000), input: money(1000) }],
      totals: { base: money(10000), output: money(1000), input: money(1000), net: money(0) },
    }));
    expect(tr.reverseCharge?.rows[0]).toMatchObject({ code: 'RC10', base: 10000, output: 1000, input: 1000, recoverable: true });
    expect(tr.reverseCharge?.totals.net).toBe(0);
  });
  it('is null when the server sent no section', () => {
    expect(parseTaxReturn(body(undefined)).reverseCharge).toBeNull();
  });
});

describe('approvals and routes', () => {
  it('lists the new documents in the inbox with a link to their own page', () => {
    const item = (document_type: string) => toApprovalItem({
      id: 'r', document_type, document_id: 'd1', requested_by: 'u', requested_by_name: 'U', requested_at: '2026-04-01T00:00:00Z', role: 'Approver', step: 1,
      document: { id: 'd1', number: null, memo: 'Insolvent', amount: money(5500), version: 1 },
    } as unknown as Parameters<typeof toApprovalItem>[0]);
    const money = (amount: number) => ({ amount, currency: 'USD' });
    expect(item('write_off')).toMatchObject({ document_type: 'write_off', api_type: 'write_off', link: { path: 'write-offs', id: 'd1' } });
    expect(item('write_off_recovery').link?.path).toBe('write-off-recoveries');
    expect(item('fund_transfer').link?.path).toBe('banking/transfers');
    expect(item('provision_run').link?.path).toBe('accounting/provisioning/runs');
  });
  it('treats the new screens as live', () => {
    const id = '0190aaaa-bbbb-7ccc-8ddd-eeeeffff0000';
    for (const p of ['/acme/banking/transfers', '/acme/banking/transfers/new', `/acme/banking/transfers/${id}`, '/acme/write-offs', '/acme/write-offs/new', `/acme/write-offs/${id}`, '/acme/write-off-recoveries/new', `/acme/write-off-recoveries/${id}`, '/acme/accounting/provisioning', `/acme/accounting/provisioning/runs/${id}`]) {
      expect(isLiveRoute(p), p).toBe(true);
    }
    expect(isLiveRoute('/acme/write-offs/not-an-id')).toBe(false);
  });
});
