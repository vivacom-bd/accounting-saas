/**
 * Fund transfer form, live (plan 3c.8). Saves a draft; the server works out the base amounts and the rates for the day. Across
 * currencies the person states what arrives, and the difference left in transit is the server's realised exchange gain or loss.
 */
import React, { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { PageHeader } from '@/components/domain/PageHeader';
import { MoneyInput } from '@/components/domain/MoneyInput';
import { Segmented } from '@/pages/Banking/reconcile/shared';
import { useRouteCompany } from '@/data/session';
import { useBankAccounts, useChartAccounts } from '@/data/banking';
import { useTx, useTxActions } from '@/data/transactions';
import { IntentSlot } from '@/api/intentSlot';
import { ApiError } from '@/api/errors';
import { toApiDate } from '@/api/adapters/dates';
import {
  blankTransferForm, toTransferForm, toTransferRequest, transferFormProblems, txErrorText,
  type ApiFundTransfer, type TransferForm,
} from '@/api/adapters/transactions';
import { BTN_ACCENT, BTN_GHOST, ErrorLine, LoadingLine, inputClass } from '@/pages/Sales/live/parts';
import { cn } from '@/lib/utils';

const Field: React.FC<{ id: string; label: string; error?: string; hint?: string; children: React.ReactNode }> = ({ id, label, error, hint, children }) => (
  <div className="flex flex-col gap-1">
    <label htmlFor={id} className="text-xs font-medium text-text-secondary">{label}</label>
    {children}
    {hint && !error && <span className="text-[11px] text-text-muted">{hint}</span>}
    {error && <span role="alert" className="text-[11px] text-danger">{error}</span>}
  </div>
);

export const TransferFormLive: React.FC = () => {
  const company = useRouteCompany();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const draftId = params.get('draft') ?? undefined;
  const banks = useBankAccounts();
  const chart = useChartAccounts();
  const draft = useTx<ApiFundTransfer>('fund-transfers', draftId);
  const actions = useTxActions<ApiFundTransfer>('fund-transfers');
  const slot = useRef(new IntentSlot()).current;
  const [form, setForm] = useState<TransferForm>(() => blankTransferForm(toApiDate(new Date())));
  const [loaded, setLoaded] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState('');
  const [busy, setBusy] = useState(false);
  const [showRates, setShowRates] = useState(false);

  useEffect(() => {
    if (draft.data && !loaded) {
      setForm(toTransferForm(draft.data));
      setLoaded(true);
    }
  }, [draft.data, loaded]);

  const active = banks.data.filter((b) => b.is_active && (b.kind === 'bank' || b.kind === 'cash'));
  const from = active.find((b) => b.id === form.from);
  const to = active.find((b) => b.id === form.to);
  const crossing = Boolean(from && to && from.currency !== to.currency);
  const feeAccounts = chart.data.filter((a) => a.is_active && a.is_postable && a.control_type === 'none' && a.account_type === 'expense');
  const set = <K extends keyof TransferForm>(k: K, v: TransferForm[K]) => setForm((f) => ({ ...f, [k]: v }));

  const save = async () => {
    const found = transferFormProblems(form, { from: from?.currency, to: to?.currency });
    setErrors(found);
    setProblem('');
    if (Object.keys(found).length > 0) return;
    const body = toTransferRequest(form, crossing, draftId ? draft.data?.version : undefined);
    setBusy(true);
    try {
      const intent = slot.for(JSON.stringify(body));
      const saved = draftId ? await actions.update(draftId, body, intent) : await actions.create(body, intent);
      slot.clear();
      toast.success('Draft saved');
      navigate(`/${company.slug}/banking/transfers/${saved.id}`);
    } catch (e) {
      if (e instanceof ApiError && e.isValidation) {
        const mapped: Record<string, string> = {};
        const names: Record<string, string> = { from_bank_account_id: 'from', to_bank_account_id: 'to', send_date: 'sendDate', from_amount_fc: 'fromAmount', to_amount_fc: 'toAmount', fee_fc: 'fee', fee_account_id: 'feeAccountId', send_rate: 'sendRate', receive_rate: 'receiveRate' };
        for (const [k, v] of Object.entries(e.errors ?? {})) mapped[names[k] ?? k] = v[0] ?? '';
        setErrors(mapped);
      }
      setProblem(txErrorText(e, 'The draft could not be saved.'));
    } finally {
      setBusy(false);
    }
  };

  if (draftId && draft.isLoading) return <LoadingLine what="the draft" />;
  if (draftId && draft.error) return <ErrorLine error={draft.error} onRetry={draft.refetch} />;
  if (draftId && draft.data && draft.data.status !== 'draft') return <p className="p-4 text-[13px] text-text-secondary">Only a draft can be edited.</p>;

  return (
    <div className="flex max-w-3xl flex-col gap-6 pb-20">
      <PageHeader
        breadcrumb={[{ label: 'Bank accounts', to: `/${company.slug}/banking` }, { label: 'Transfers', to: `/${company.slug}/banking/transfers` }, { label: draftId ? 'Edit draft' : 'New transfer' }]}
        title={draftId ? 'Edit transfer draft' : 'New transfer'}
        subtitle="Move money between two of your own accounts."
      />
      {banks.isLoading ? <LoadingLine what="your accounts" /> : active.length < 2 ? (
        <p className="rounded-lg border border-border bg-surface p-4 text-[13px] text-text-secondary">A transfer needs at least two active bank or cash accounts. Add another one on the Bank accounts page.</p>
      ) : (
        <form onSubmit={(e) => { e.preventDefault(); void save(); }} className="flex flex-col gap-5 rounded-lg border border-border bg-surface p-6" aria-label="Transfer">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="tf-from" label="From" error={errors.from}>
              <select id="tf-from" value={form.from} onChange={(e) => set('from', e.target.value)} className={cn(inputClass, 'w-full')}>
                <option value="">Choose an account</option>
                {active.map((b) => <option key={b.id} value={b.id}>{b.name} ({b.currency})</option>)}
              </select>
            </Field>
            <Field id="tf-to" label="To" error={errors.to}>
              <select id="tf-to" value={form.to} onChange={(e) => set('to', e.target.value)} className={cn(inputClass, 'w-full')}>
                <option value="">Choose an account</option>
                {active.filter((b) => b.id !== form.from).map((b) => <option key={b.id} value={b.id}>{b.name} ({b.currency})</option>)}
              </select>
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field id="tf-date" label="Date sent" error={errors.sendDate}>
              <input id="tf-date" type="date" value={form.sendDate} onChange={(e) => set('sendDate', e.target.value)} className={cn(inputClass, 'w-full')} />
            </Field>
            <Field id="tf-amount" label={`Amount sent${from ? ` (${from.currency})` : ''}`} error={errors.fromAmount}>
              <MoneyInput id="tf-amount" value={form.fromAmount} onChange={(v) => set('fromAmount', v)} currency={from?.currency ?? company.base_currency} allowNegative={false} size="sm" />
            </Field>
            {crossing && (
              <Field id="tf-toamount" label={`Amount that arrives (${to?.currency})`} error={errors.toAmount} hint="What the other bank actually credits you.">
                <MoneyInput id="tf-toamount" value={form.toAmount} onChange={(v) => set('toAmount', v)} currency={to?.currency ?? company.base_currency} allowNegative={false} size="sm" />
              </Field>
            )}
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            <Field id="tf-fee" label={`Bank fee${from ? ` (${from.currency})` : ''}`} error={errors.fee} hint="Optional. Taken from the sending account on top of the amount.">
              <MoneyInput id="tf-fee" value={form.fee} onChange={(v) => set('fee', v)} currency={from?.currency ?? company.base_currency} allowNegative={false} size="sm" />
            </Field>
            {form.fee > 0 && (
              <Field id="tf-feeacct" label="Fee is charged to" error={errors.feeAccountId}>
                <select id="tf-feeacct" value={form.feeAccountId} onChange={(e) => set('feeAccountId', e.target.value)} className={cn(inputClass, 'w-full')}>
                  <option value="">Choose an expense account</option>
                  {feeAccounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                </select>
              </Field>
            )}
          </div>

          <div className="flex flex-col gap-2">
            <span className="text-xs font-medium text-text-secondary">When does the money arrive?</span>
            <Segmented
              value={form.sameDay ? 'same' : 'later'}
              onChange={(v) => set('sameDay', v === 'same')}
              options={[{ value: 'later', label: 'Later, I will record it' }, { value: 'same', label: 'The same day' }]}
            />
            <span className="text-[11px] text-text-muted">
              {form.sameDay ? 'Both halves are posted together when this is approved. Nothing waits in transit.' : 'The money waits in funds in transit until you record that it arrived.'}
            </span>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="tf-ref" label="Reference">
              <input id="tf-ref" value={form.reference} onChange={(e) => set('reference', e.target.value)} className={cn(inputClass, 'w-full')} maxLength={255} />
            </Field>
            <Field id="tf-memo" label="Note">
              <input id="tf-memo" value={form.memo} onChange={(e) => set('memo', e.target.value)} className={cn(inputClass, 'w-full')} />
            </Field>
          </div>

          {(from && from.currency !== company.base_currency) || (to && to.currency !== company.base_currency) ? (
            <div className="flex flex-col gap-2">
              <button type="button" onClick={() => setShowRates((s) => !s)} className="w-fit text-xs text-accent hover:underline">
                {showRates ? 'Use the stored rates' : 'Set the exchange rates myself'}
              </button>
              {showRates && (
                <div className="grid gap-4 sm:grid-cols-2">
                  {from && from.currency !== company.base_currency && (
                    <Field id="tf-srate" label={`Rate when sent (${from.currency} to ${company.base_currency})`} error={errors.sendRate} hint="Leave empty to use the rate stored for the day.">
                      <input id="tf-srate" inputMode="decimal" value={form.sendRate} onChange={(e) => set('sendRate', e.target.value)} className={cn(inputClass, 'w-full tabular-nums')} />
                    </Field>
                  )}
                  {to && to.currency !== company.base_currency && (
                    <Field id="tf-rrate" label={`Rate when received (${to.currency} to ${company.base_currency})`} error={errors.receiveRate} hint="Leave empty to use the rate stored for the day.">
                      <input id="tf-rrate" inputMode="decimal" value={form.receiveRate} onChange={(e) => set('receiveRate', e.target.value)} className={cn(inputClass, 'w-full tabular-nums')} />
                    </Field>
                  )}
                </div>
              )}
            </div>
          ) : null}

          {problem && <p role="alert" className="text-[13px] text-danger">{problem}</p>}
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => navigate(`/${company.slug}/banking/transfers`)} className={BTN_GHOST}>Cancel</button>
            <button type="submit" disabled={busy} className={BTN_ACCENT}>{busy ? 'Saving…' : 'Save draft'}</button>
          </div>
          <p className="text-[11px] text-text-muted">Saving a draft puts nothing in your books. You can see the journal lines it would make on the next page, before you submit.</p>
        </form>
      )}
    </div>
  );
};
