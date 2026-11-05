/**
 * Write-off form and recovery form, live (plan 3c.9, 3c.12a). Both are one amount, one date and one reason against a single document
 * (an invoice, or a posted write-off). The server checks the amount against what is open or still unrecovered; the figures shown are
 * the server's, and the "all of it" buttons only copy a number the server sent.
 */
import React, { useEffect, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { PageHeader } from '@/components/domain/PageHeader';
import { MoneyInput } from '@/components/domain/MoneyInput';
import { useRouteCompany } from '@/data/session';
import { useDoc } from '@/data/invoices';
import { useContactDirectory } from '@/data/contacts';
import { useTx, useTxActions } from '@/data/transactions';
import { IntentSlot } from '@/api/intentSlot';
import { ApiError } from '@/api/errors';
import { toApiDate } from '@/api/adapters/dates';
import {
  amountReasonProblems, minor, toRecoveryRequest, toWriteOffRequest, txErrorText,
  type AmountReasonForm, type ApiRecovery, type ApiWriteOff,
} from '@/api/adapters/transactions';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { BTN_ACCENT, BTN_GHOST, ErrorLine, LoadingLine, inputClass } from './parts';

const Field: React.FC<{ id: string; label: string; error?: string; hint?: string; children: React.ReactNode }> = ({ id, label, error, hint, children }) => (
  <div className="flex flex-col gap-1">
    <label htmlFor={id} className="text-xs font-medium text-text-secondary">{label}</label>
    {children}
    {hint && !error && <span className="text-[11px] text-text-muted">{hint}</span>}
    {error && <span role="alert" className="text-[11px] text-danger">{error}</span>}
  </div>
);

interface Shared {
  noun: 'write-off' | 'recovery';
  currency: string;
  max: number;
  maxLabel: string;
  context: React.ReactNode;
  initial: AmountReasonForm;
  reasonLabel: string;
  reasonHint: string;
  saveLabel: string;
  cancelTo: string;
  onSave: (f: AmountReasonForm) => Promise<string>;
  /** Name of the server field in a 422 -> form field. */
  fieldMap: Record<string, string>;
}

const AmountReason: React.FC<Shared> = ({ noun, currency, max, maxLabel, context, initial, reasonLabel, reasonHint, saveLabel, cancelTo, onSave, fieldMap }) => {
  const navigate = useNavigate();
  const [form, setForm] = useState<AmountReasonForm>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState('');
  const [busy, setBusy] = useState(false);
  const set = <K extends keyof AmountReasonForm>(k: K, v: AmountReasonForm[K]) => setForm((f) => ({ ...f, [k]: v }));

  const save = async () => {
    const found = amountReasonProblems(form, max, noun);
    setErrors(found);
    setProblem('');
    if (Object.keys(found).length > 0) return;
    setBusy(true);
    try {
      navigate(await onSave(form));
    } catch (e) {
      if (e instanceof ApiError && e.isValidation) {
        const mapped: Record<string, string> = {};
        for (const [k, v] of Object.entries(e.errors ?? {})) mapped[fieldMap[k] ?? k] = v[0] ?? '';
        setErrors(mapped);
      }
      setProblem(txErrorText(e, 'The draft could not be saved.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={(e) => { e.preventDefault(); void save(); }} className="flex max-w-2xl flex-col gap-5 rounded-lg border border-border bg-surface p-6" aria-label={noun === 'write-off' ? 'Write-off' : 'Recovery'}>
      {context}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="ar-amount" label={`Amount (${currency})`} error={errors.amount} hint={`${maxLabel} ${formatMoney(max, currency)}`}>
          <MoneyInput id="ar-amount" value={form.amount} onChange={(v) => set('amount', v)} currency={currency} allowNegative={false} size="sm" />
        </Field>
        <Field id="ar-date" label="Date" error={errors.date}>
          <input id="ar-date" type="date" value={form.date} onChange={(e) => set('date', e.target.value)} className={cn(inputClass, 'w-full')} />
        </Field>
      </div>
      <div className="flex gap-2">
        <button type="button" onClick={() => set('amount', max)} className="rounded border border-border px-2 py-1 text-[11px] hover:bg-surface-neutral">
          {noun === 'write-off' ? 'All that is owed' : 'All that was written off'}
        </button>
      </div>
      <Field id="ar-reason" label={reasonLabel} error={errors.reason} hint={reasonHint}>
        <textarea id="ar-reason" rows={3} value={form.reason} onChange={(e) => set('reason', e.target.value)} className="w-full rounded-md border border-border bg-surface px-2.5 py-2 text-[13px] focus:border-accent focus:outline-none" />
      </Field>
      <Field id="ar-ref" label="Reference (optional)">
        <input id="ar-ref" value={form.reference} onChange={(e) => set('reference', e.target.value)} maxLength={255} className={cn(inputClass, 'w-full')} />
      </Field>
      {problem && <p role="alert" className="text-[13px] text-danger">{problem}</p>}
      <div className="flex justify-end gap-2">
        <button type="button" onClick={() => navigate(cancelTo)} className={BTN_GHOST}>Cancel</button>
        <button type="submit" disabled={busy} className={BTN_ACCENT}>{busy ? 'Saving…' : saveLabel}</button>
      </div>
      <p className="text-[11px] text-text-muted">Saving puts nothing in your books. The next page shows the journal lines this would make, before you submit.</p>
    </form>
  );
};

export const WriteOffFormLive: React.FC = () => {
  const company = useRouteCompany();
  const co = `/${company.slug}`;
  const [params] = useSearchParams();
  const invoiceParam = params.get('invoice') ?? undefined;
  const draftId = params.get('draft') ?? undefined;
  const draft = useTx<ApiWriteOff>('write-offs', draftId);
  const invoiceId = invoiceParam ?? draft.data?.invoice_id;
  const invoice = useDoc(invoiceId);
  const customers = useContactDirectory('customer', true);
  const actions = useTxActions<ApiWriteOff>('write-offs');
  const slot = useRef(new IntentSlot()).current;
  const [ready, setReady] = useState(false);
  useEffect(() => setReady(Boolean(invoice.data) && (!draftId || Boolean(draft.data))), [invoice.data, draft.data, draftId]);

  if (!invoiceId && !draftId) return <p className="p-4 text-[13px] text-text-secondary">A write-off starts from an invoice. Open the invoice and choose Write off.</p>;
  if (invoice.isLoading || draft.isLoading || !ready) return invoice.error ? <ErrorLine error={invoice.error} onRetry={invoice.refetch} /> : <LoadingLine what="the invoice" />;
  const inv = invoice.data;
  if (!inv) return <ErrorLine error={invoice.error} onRetry={invoice.refetch} />;
  if (inv.kind !== 'sales_invoice' || inv.status !== 'posted') return <p className="p-4 text-[13px] text-text-secondary">Only a posted customer invoice can be written off.</p>;
  if (draftId && draft.data && draft.data.status !== 'draft') return <p className="p-4 text-[13px] text-text-secondary">Only a draft can be edited.</p>;
  const customer = customers.byId.get(inv.contact_id);
  // What the invoice owes now. When editing a draft, that is still what the server will check against.
  const open = inv.amount_due_fc;

  return (
    <div className="flex flex-col gap-6 pb-20">
      <PageHeader
        breadcrumb={[{ label: 'Invoices', to: `${co}/invoices` }, { label: inv.number ?? 'Invoice', to: `${co}/invoices/${inv.id}` }, { label: 'Write off' }]}
        title={draftId ? 'Edit write-off draft' : 'Write off a debt'}
        subtitle={`${customer?.name ?? 'Customer'} · ${inv.number ?? ''}`}
      />
      <AmountReason
        noun="write-off"
        currency={inv.currency}
        max={open}
        maxLabel="The invoice still owes"
        context={
          <dl className="grid grid-cols-[140px_1fr] gap-x-4 gap-y-1 rounded-md bg-surface-neutral p-3 text-xs">
            <dt className="text-text-muted">Invoice</dt><dd className="text-text-primary">{inv.number} dated {formatDate(inv.issue_date)}</dd>
            <dt className="text-text-muted">Total</dt><dd className="tabular-nums text-text-primary">{formatMoney(inv.total_fc, inv.currency)}</dd>
            <dt className="text-text-muted">Still owed</dt><dd className="tabular-nums font-medium text-text-primary">{formatMoney(open, inv.currency)}</dd>
          </dl>
        }
        initial={draft.data ? { date: draft.data.write_off_date ?? toApiDate(new Date()), amount: minor(draft.data.amount), reason: draft.data.reason, reference: draft.data.reference ?? '' } : { date: toApiDate(new Date()), amount: open, reason: '', reference: '' }}
        reasonLabel="Why is this debt written off?"
        reasonHint="For example: the customer has gone out of business. This stays with the write-off for your auditors."
        saveLabel="Save draft"
        cancelTo={`${co}/invoices/${inv.id}`}
        fieldMap={{ write_off_date: 'date', amount_fc: 'amount', invoice_id: 'amount' }}
        onSave={async (f) => {
          const body = toWriteOffRequest(inv.id, f, draftId ? draft.data?.version : undefined);
          const intent = slot.for(JSON.stringify(body));
          const saved = draftId ? await actions.update(draftId, body, intent) : await actions.create(body, intent);
          slot.clear();
          toast.success('Draft saved');
          return `${co}/write-offs/${saved.id}`;
        }}
      />
    </div>
  );
};

export const RecoveryFormLive: React.FC = () => {
  const company = useRouteCompany();
  const co = `/${company.slug}`;
  const [params] = useSearchParams();
  const writeOffId = params.get('writeOff') ?? undefined;
  const draftId = params.get('draft') ?? undefined;
  const draft = useTx<ApiRecovery>('write-off-recoveries', draftId);
  const wid = writeOffId ?? draft.data?.write_off_id;
  const w = useTx<ApiWriteOff>('write-offs', wid);
  const customers = useContactDirectory('customer', true);
  const actions = useTxActions<ApiRecovery>('write-off-recoveries');
  const slot = useRef(new IntentSlot()).current;

  if (!wid) return <p className="p-4 text-[13px] text-text-secondary">A recovery starts from a posted write-off. Open the write-off and choose Record a recovery.</p>;
  if (w.isLoading || draft.isLoading) return <LoadingLine what="the write-off" />;
  if (w.error || !w.data) return <ErrorLine error={w.error} onRetry={w.refetch} />;
  const wo = w.data;
  if (wo.status !== 'posted') return <p className="p-4 text-[13px] text-text-secondary">Only a posted write-off can be recovered.</p>;
  if (draftId && draft.data && draft.data.status !== 'draft') return <p className="p-4 text-[13px] text-text-secondary">Only a draft can be edited.</p>;
  const left = minor(wo.recoverable);

  return (
    <div className="flex flex-col gap-6 pb-20">
      <PageHeader
        breadcrumb={[{ label: 'Write-offs', to: `${co}/write-offs` }, { label: wo.number ?? 'Write-off', to: `${co}/write-offs/${wo.id}` }, { label: 'Recovery' }]}
        title={draftId ? 'Edit recovery draft' : 'Record a recovery'}
        subtitle={`${customers.byId.get(wo.contact_id)?.name ?? 'Customer'} · after write-off ${wo.number ?? ''}`}
      />
      <AmountReason
        noun="recovery"
        currency={wo.amount.currency}
        max={left}
        maxLabel="Can still be recovered, up to"
        context={
          <div className="rounded-md bg-surface-neutral p-3 text-xs text-text-secondary">
            <p>
              {formatMoney(minor(wo.amount), wo.amount.currency)} was written off on {formatDate(wo.write_off_date)}
              {minor(wo.recovered) > 0 ? `, and ${formatMoney(minor(wo.recovered), wo.recovered.currency)} has come back since` : ''}.
            </p>
            <p className="mt-1">A recovery puts the debt back on the customer’s account and records it as a bad debt recovered. The invoice reopens for that amount. The money itself comes in through a normal receipt afterwards.</p>
          </div>
        }
        initial={draft.data ? { date: draft.data.recovery_date ?? toApiDate(new Date()), amount: minor(draft.data.amount), reason: draft.data.reason, reference: draft.data.reference ?? '' } : { date: toApiDate(new Date()), amount: left, reason: '', reference: '' }}
        reasonLabel="How did the debt come back?"
        reasonHint="For example: the customer agreed a payment plan, or the liquidator paid a dividend."
        saveLabel="Save draft"
        cancelTo={`${co}/write-offs/${wo.id}`}
        fieldMap={{ recovery_date: 'date', amount_fc: 'amount', write_off_id: 'amount' }}
        onSave={async (f) => {
          const body = toRecoveryRequest(wo.id, f, draftId ? draft.data?.version : undefined);
          const intent = slot.for(JSON.stringify(body));
          const saved = draftId ? await actions.update(draftId, body, intent) : await actions.create(body, intent);
          slot.clear();
          toast.success('Draft saved');
          return `${co}/write-off-recoveries/${saved.id}`;
        }}
      />
    </div>
  );
};
