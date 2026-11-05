/**
 * The transaction documents of plan 3c.8 to 3c.12 at the API boundary: fund transfers, write-offs, recoveries after write-off,
 * provisioning, and the credit-limit refusal. Pure functions. The server computes every amount, rate difference and verdict; nothing in
 * here adds, converts or rounds money. Rates stay decimal strings, percentages stay decimal strings.
 */
import type { components } from '../schema';
import { ApiError } from '../errors';
import { fromApiMoney, type ApiMoney } from './money';

export type ApiFundTransfer = components['schemas']['FundTransferResource'];
export type ApiWriteOff = components['schemas']['WriteOffResource'];
export type ApiRecovery = components['schemas']['WriteOffRecoveryResource'];
export type ApiProvisionRun = components['schemas']['ProvisionRunResource'];
export type ApiProvisionPreview = components['schemas']['ProvisionPreviewResource'];

/** The families of document that go through the shared workflow card. */
export type TxFamily = 'fund-transfers' | 'write-offs' | 'write-off-recoveries' | 'provision-runs';

export type TxStatus = 'draft' | 'pending_approval' | 'posted' | 'reversed' | 'void';

export interface TxCan {
  update: boolean;
  submit: boolean;
  approve: boolean;
  return: boolean;
  void: boolean;
  reverse: boolean;
  /** Fund transfer only. */
  receive: boolean;
  /** Write-off only: may record a recovery. */
  recover: boolean;
}

export function toTxCan(raw: unknown): TxCan {
  const r = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>;
  const b = (k: string) => r[k] === true;
  return { update: b('update'), submit: b('submit'), approve: b('approve'), return: b('return'), void: b('void'), reverse: b('reverse'), receive: b('receive'), recover: b('recover') };
}

type MoneyLike = { amount: number | null; currency: string };
export const minor = (m: MoneyLike | null | undefined): number => (m && typeof m.amount === 'number' ? fromApiMoney(m as ApiMoney) : 0);

/* ----------------------------------------------------------------- statuses */

const STATUS_WORDS: Record<TxStatus, string> = {
  draft: 'Draft',
  pending_approval: 'Waiting for approval',
  posted: 'In the books',
  reversed: 'Reversed',
  void: 'Discarded',
};
export const txStatusWord = (s: string): string => STATUS_WORDS[s as TxStatus] ?? s;

/** Where the money of a transfer is, in plain words. */
export function legStateText(t: Pick<ApiFundTransfer, 'status' | 'leg_state' | 'received_on'>): { label: string; tone: 'neutral' | 'warn' | 'ok' } {
  if (t.status === 'reversed') return { label: 'Reversed. The money is back where it started.', tone: 'neutral' };
  if (t.status === 'void') return { label: 'Discarded. Nothing was moved.', tone: 'neutral' };
  if (t.status !== 'posted') return { label: 'Not sent yet. Nothing has left the account.', tone: 'neutral' };
  if (t.leg_state === 'completed') return { label: 'Received. The money has arrived in the other account.', tone: 'ok' };
  return { label: 'In transit. It has left the first account and has not arrived yet.', tone: 'warn' };
}

/* ------------------------------------------------------------- fund transfers */

export interface TransferForm {
  from: string;
  to: string;
  sendDate: string;
  /** Minor units of the sending account's currency. */
  fromAmount: number;
  /** Minor units of the receiving account's currency; used when the currencies differ, or to state a different amount. */
  toAmount: number;
  /** Decimal strings, or '' to use the stored rate for the day. */
  sendRate: string;
  receiveRate: string;
  fee: number;
  feeAccountId: string;
  sameDay: boolean;
  reference: string;
  memo: string;
}

export const blankTransferForm = (today: string): TransferForm => ({
  from: '', to: '', sendDate: today, fromAmount: 0, toAmount: 0, sendRate: '', receiveRate: '', fee: 0, feeAccountId: '', sameDay: false, reference: '', memo: '',
});

const RATE = /^\d{1,12}(\.\d{1,8})?$/;
export const isRate = (s: string): boolean => RATE.test(s.trim()) && Number(s) > 0;

/** What is wrong with the form before it is sent. The server checks everything again; this only saves a round trip. */
export function transferFormProblems(f: TransferForm, currencies: { from?: string; to?: string }): Record<string, string> {
  const e: Record<string, string> = {};
  if (!f.from) e.from = 'Choose the account the money leaves.';
  if (!f.to) e.to = 'Choose the account the money goes to.';
  if (f.from && f.from === f.to) e.to = 'Choose two different accounts.';
  if (!f.sendDate) e.sendDate = 'Choose the date it is sent.';
  if (f.fromAmount <= 0) e.fromAmount = 'Enter the amount to send.';
  const crossing = Boolean(currencies.from && currencies.to && currencies.from !== currencies.to);
  if (crossing && f.toAmount <= 0) e.toAmount = `Enter the amount that arrives in ${currencies.to}.`;
  if (f.fee > 0 && !f.feeAccountId) e.feeAccountId = 'Choose the expense account the fee is charged to.';
  if (f.sendRate.trim() && !isRate(f.sendRate)) e.sendRate = 'A rate is a positive number with up to 8 decimals.';
  if (f.receiveRate.trim() && !isRate(f.receiveRate)) e.receiveRate = 'A rate is a positive number with up to 8 decimals.';
  return e;
}

/** Body for POST / PUT fund-transfers. Fields the person left empty are left out so the server decides. */
export function toTransferRequest(f: TransferForm, crossing: boolean, version?: number): Record<string, unknown> {
  const body: Record<string, unknown> = {
    from_bank_account_id: f.from,
    to_bank_account_id: f.to,
    send_date: f.sendDate,
    from_amount_fc: f.fromAmount,
    settle_same_day: f.sameDay,
    reference: f.reference.trim() || null,
    memo: f.memo.trim() || null,
  };
  if (crossing || f.toAmount > 0) body.to_amount_fc = f.toAmount > 0 ? f.toAmount : null;
  if (f.sendRate.trim()) body.send_rate = f.sendRate.trim();
  if (f.receiveRate.trim()) body.receive_rate = f.receiveRate.trim();
  if (f.fee > 0) {
    body.fee_fc = f.fee;
    body.fee_account_id = f.feeAccountId;
  }
  if (version !== undefined) body.version = version;
  return body;
}

export function toTransferForm(t: ApiFundTransfer): TransferForm {
  return {
    from: t.from_bank_account_id,
    to: t.to_bank_account_id,
    sendDate: t.send_date ?? '',
    fromAmount: minor(t.from_amount),
    toAmount: minor(t.to_amount),
    sendRate: '',
    receiveRate: '',
    fee: minor(t.fee),
    feeAccountId: t.fee_account_id ?? '',
    sameDay: t.settle_same_day,
    reference: t.reference ?? '',
    memo: t.memo ?? '',
  };
}

export interface ReceiveForm {
  receivedOn: string;
  /** Minor units of the destination currency; 0 means "as stated on the transfer". */
  amount: number;
  rate: string;
}

export function receiveProblems(f: ReceiveForm, sendDate: string): Record<string, string> {
  const e: Record<string, string> = {};
  if (!f.receivedOn) e.receivedOn = 'Enter the date the money arrived.';
  else if (sendDate && f.receivedOn < sendDate) e.receivedOn = 'The money cannot arrive before it was sent.';
  if (f.rate.trim() && !isRate(f.rate)) e.rate = 'A rate is a positive number with up to 8 decimals.';
  return e;
}

export function toReceiveRequest(f: ReceiveForm, version: number): Record<string, unknown> {
  return {
    received_on: f.receivedOn,
    ...(f.amount > 0 ? { to_amount_fc: f.amount } : {}),
    ...(f.rate.trim() ? { receive_rate: f.rate.trim() } : {}),
    version,
  };
}

/** The realised gain or loss on receipt, in the server's sign: positive is a gain. */
export function fxDifferenceText(diff: MoneyLike | null | undefined): { kind: 'gain' | 'loss' | 'none'; amount: number } {
  const n = diff && typeof diff.amount === 'number' ? diff.amount : 0;
  return n > 0 ? { kind: 'gain', amount: n } : n < 0 ? { kind: 'loss', amount: -n } : { kind: 'none', amount: 0 };
}

/* ------------------------------------------------- write-offs and recoveries */

export interface AmountReasonForm {
  date: string;
  /** Minor units of the invoice's currency. */
  amount: number;
  reason: string;
  reference: string;
}

export function amountReasonProblems(f: AmountReasonForm, max: number, noun: 'write-off' | 'recovery'): Record<string, string> {
  const e: Record<string, string> = {};
  if (!f.date) e.date = 'Choose a date.';
  if (f.amount <= 0) e.amount = 'Enter an amount above zero.';
  else if (max > 0 && f.amount > max) e.amount = noun === 'write-off' ? 'That is more than the invoice still owes.' : 'That is more than what was written off and not yet recovered.';
  if (f.reason.trim().length < 3) e.reason = noun === 'write-off' ? 'Say why the debt is written off (at least a few words).' : 'Say how the debt came back (at least a few words).';
  return e;
}

export function toWriteOffRequest(invoiceId: string, f: AmountReasonForm, version?: number): Record<string, unknown> {
  return {
    invoice_id: invoiceId, write_off_date: f.date, amount_fc: f.amount, reason: f.reason.trim(), reference: f.reference.trim() || null,
    ...(version !== undefined ? { version } : {}),
  };
}

export function toRecoveryRequest(writeOffId: string, f: AmountReasonForm, version?: number): Record<string, unknown> {
  return {
    write_off_id: writeOffId, recovery_date: f.date, amount_fc: f.amount, reason: f.reason.trim(), reference: f.reference.trim() || null,
    ...(version !== undefined ? { version } : {}),
  };
}

/* --------------------------------------------------------------- provisioning */

export const BUCKETS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90'] as const;
export type Bucket = (typeof BUCKETS)[number];

export const BUCKET_LABELS: Record<Bucket, string> = {
  current: 'Not yet due',
  days_1_30: '1 to 30 days late',
  days_31_60: '31 to 60 days late',
  days_61_90: '61 to 90 days late',
  days_over_90: 'More than 90 days late',
};

/** A rate is a percentage from 0 to 100 with up to four decimals, typed as text and sent as text. */
const PERCENT = /^(100(\.0{1,4})?|\d{1,2}(\.\d{1,4})?)$/;
export const isPercent = (s: string): boolean => PERCENT.test(s.trim());

export type RatesForm = Record<Bucket, string>;

export const blankRates = (): RatesForm => ({ current: '', days_1_30: '', days_31_60: '', days_61_90: '', days_over_90: '' });

export function ratesProblems(f: RatesForm): Partial<Record<Bucket, string>> {
  const e: Partial<Record<Bucket, string>> = {};
  for (const b of BUCKETS) {
    if (f[b].trim() === '') e[b] = 'State a rate, 0 if you expect no loss.';
    else if (!isPercent(f[b])) e[b] = 'A percentage from 0 to 100, up to four decimals.';
  }
  return e;
}

export function toRatesRequest(f: RatesForm): { rates: Record<Bucket, string> } {
  return { rates: { current: f.current.trim(), days_1_30: f.days_1_30.trim(), days_31_60: f.days_31_60.trim(), days_61_90: f.days_61_90.trim(), days_over_90: f.days_over_90.trim() } };
}

export interface BucketRow {
  bucket: string;
  label: string;
  balance: number;
  rate_pct: string;
  amount: number;
  currency: string;
}

/** The server types `buckets` as an open list; every entry carries these keys. */
export function toBucketRows(raw: unknown[]): BucketRow[] {
  return raw.map((x) => {
    const r = x as { bucket: string; label: string; balance: ApiMoney; rate_pct: string; amount: ApiMoney };
    return { bucket: r.bucket, label: r.label, balance: minor(r.balance), rate_pct: r.rate_pct, amount: minor(r.amount), currency: r.balance.currency };
  });
}

/* -------------------------------------------------------------- error words */

const TEXT: Record<string, string> = {
  provision_run_stale: 'The receivables or the allowance changed after this run was prepared, so its figures are out of date. Discard it and prepare a new one.',
  provision_run_exists: 'This period already has a provision run. Reverse it first if you want to run again.',
  provision_no_difference: 'The allowance already equals what the rates require, so there is nothing to post.',
  provision_rates_missing: 'Set a rate for every aging bucket before running a provision.',
  write_off_exceeds_open_amount: 'That is more than the invoice still owes.',
  write_off_recovered: 'Part of this write-off has been recovered. Reverse the recovery first.',
  recovery_exceeds_written_off: 'That is more than what was written off and not yet recovered.',
  recovery_amount_used: 'A receipt has already used the amount this recovery reopened. Reverse that receipt first.',
  recovery_before_write_off: 'A recovery cannot be dated before the write-off.',
  transfer_received_before_sent: 'The money cannot arrive before it was sent.',
  transfer_not_in_transit: 'This transfer is not waiting to be received.',
  transfer_same_account: 'Choose two different accounts.',
  transfer_received_amount_required: 'State the amount that arrives in the other account’s currency.',
  posting_account_missing: 'A posting account this needs is not set. Settings, Posting, shows which.',
  period_closed: 'That date falls in a closed period.',
  credit_limit_exceeded: 'This customer would be over their credit limit.',
  credit_limit_override_forbidden: 'You cannot override a credit limit.',
  credit_limit_override_self_forbidden: 'You made this invoice, so someone else has to override its credit limit.',
};

/** The server's message is already plain; a few codes get a calmer, more specific sentence. */
export function txErrorText(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const own = TEXT[e.code];
    if (own) return own;
    const first = e.violations?.map((v) => TEXT[v.code] ?? v.message).filter(Boolean)[0];
    if (first) return first;
    if (e.detail) return e.detail;
  }
  return fallback;
}

/* --------------------------------------------------------------- credit limit */

export interface CreditWarning {
  code: string;
  message: string;
}

/** `warnings` of a submit answer: only well-formed entries. */
export function readWarnings(body: unknown): CreditWarning[] {
  const raw = (body as { warnings?: unknown } | null)?.warnings;
  if (!Array.isArray(raw)) return [];
  return raw.filter((w): w is CreditWarning => Boolean(w) && typeof (w as CreditWarning).code === 'string' && typeof (w as CreditWarning).message === 'string');
}

/** True when a submit was refused only because of the credit limit. */
export const isCreditLimitRefusal = (e: unknown): boolean => e instanceof ApiError && (e.code === 'credit_limit_exceeded' || (e.violations ?? []).some((v) => v.code === 'credit_limit_exceeded'));

export type CreditLimitMode = 'off' | 'warn' | 'refuse';
export const CREDIT_LIMIT_MODES: { value: CreditLimitMode; label: string; hint: string }[] = [
  { value: 'off', label: 'Off', hint: 'Invoices are never checked against a credit limit.' },
  { value: 'warn', label: 'Warn', hint: 'An invoice that takes a customer over their limit still goes ahead, with a warning.' },
  { value: 'refuse', label: 'Refuse', hint: 'An invoice over the limit is stopped. A second person with the right permission can let it through, with a reason.' },
];
