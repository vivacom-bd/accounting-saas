/**
 * Live billing operations (10 §3.5, plan Task 13): the all-tenant invoice mirror, refund and credit-note requests
 * (which need a second staff member), the tax report, deferred revenue and the payouts reconciliation with CSV exports.
 * Money is integer minor units shown with the currency's own exponent.
 */
import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { formatMoney } from '@/lib/format';
import {
  creditNoteInvoice, downloadCsv, getPayouts, getRevenueRecognition, getTaxReport, listInvoices, markInvoicePaid, recordOfflineInvoice, refundInvoice,
  type Invoice,
} from '@/api/live/platform/billing';
import { Field, FormError, LoadError, Loading, field, primary, secondary } from '@/components/live/form';
import { useAdminSession } from '@/data/adminSession';

const today = () => new Date().toISOString().slice(0, 10);
const firstOfMonth = () => `${new Date().toISOString().slice(0, 7)}-01`;
const thisMonth = () => new Date().toISOString().slice(0, 7);

type Tab = 'invoices' | 'tax' | 'revenue' | 'payouts';

const InvoicesTab: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const qc = useQueryClient();
  const [filters, setFilters] = useState({ status: '', tenant: '', currency: '', from: '', to: '' });
  const [cursor, setCursor] = useState<string | undefined>();
  const [target, setTarget] = useState<{ invoice: Invoice; kind: 'refund' | 'credit_note' } | null>(null);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [offline, setOffline] = useState(false);
  const [o, setO] = useState({ tenant_id: '', number: '', currency: 'USD', subtotal: '', tax: '0', due_at: today() });

  const q = useQuery({ queryKey: ['admin', 'billing', 'invoices', filters, cursor ?? null], queryFn: () => listInvoices(filters, cursor) });
  const refresh = () => { setCursor(undefined); void qc.invalidateQueries({ queryKey: ['admin', 'billing', 'invoices'] }); };
  const refund = useMutation({
    mutationFn: () => target!.kind === 'credit_note'
      ? creditNoteInvoice(target!.invoice.id, Number(amount), reason.trim())
      : refundInvoice(target!.invoice.id, Number(amount), reason.trim()),
    onSuccess: () => { toast.success('Request sent — waiting for a second approver.'); setTarget(null); setAmount(''); setReason(''); refresh(); },
  });
  const markPaid = useMutation({ mutationFn: (id: string) => markInvoicePaid(id), onSuccess: () => { toast.success('Invoice marked paid.'); refresh(); } });
  const record = useMutation({
    mutationFn: () => recordOfflineInvoice({ ...o, subtotal: Number(o.subtotal), tax: Number(o.tax) }),
    onSuccess: () => { toast.success('Offline invoice recorded.'); setOffline(false); setO({ tenant_id: '', number: '', currency: 'USD', subtotal: '', tax: '0', due_at: today() }); refresh(); },
  });

  return (
    <div className="flex flex-col gap-3">
      <form className="grid grid-cols-2 items-end gap-2 sm:grid-cols-6" onSubmit={(e) => { e.preventDefault(); setCursor(undefined); }}>
        <Field id="bi-status" label="Status"><select id="bi-status" className={field} value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
          <option value="">All</option>{['draft', 'open', 'paid', 'void', 'uncollectible'].map((s) => <option key={s} value={s}>{s}</option>)}
        </select></Field>
        <Field id="bi-tenant" label="Tenant id"><input id="bi-tenant" className={field} value={filters.tenant} onChange={(e) => setFilters({ ...filters, tenant: e.target.value })} /></Field>
        <Field id="bi-currency" label="Currency"><input id="bi-currency" className={field} maxLength={3} value={filters.currency} onChange={(e) => setFilters({ ...filters, currency: e.target.value.toUpperCase() })} /></Field>
        <Field id="bi-from" label="From"><input id="bi-from" type="date" className={field} value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} /></Field>
        <Field id="bi-to" label="To"><input id="bi-to" type="date" className={field} value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} /></Field>
        <button type="submit" className={secondary}>Filter</button>
      </form>

      {canManage && (
        <div className="flex justify-end">
          <button type="button" className={secondary} onClick={() => setOffline((v) => !v)}>{offline ? 'Close' : 'Record offline invoice'}</button>
        </div>
      )}
      {offline && canManage && (
        <form className="grid grid-cols-2 items-end gap-2 rounded-lg border border-border bg-surface p-3 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); record.mutate(); }}>
          <Field id="off-tenant" label="Tenant id"><input id="off-tenant" className={field} value={o.tenant_id} onChange={(e) => setO({ ...o, tenant_id: e.target.value })} /></Field>
          <Field id="off-number" label="Number"><input id="off-number" className={field} value={o.number} onChange={(e) => setO({ ...o, number: e.target.value })} /></Field>
          <Field id="off-cur" label="Currency"><input id="off-cur" className={field} maxLength={3} value={o.currency} onChange={(e) => setO({ ...o, currency: e.target.value.toUpperCase() })} /></Field>
          <Field id="off-due" label="Due"><input id="off-due" type="date" className={field} value={o.due_at} onChange={(e) => setO({ ...o, due_at: e.target.value })} /></Field>
          <Field id="off-sub" label="Subtotal (minor units)"><input id="off-sub" inputMode="numeric" className={field} value={o.subtotal} onChange={(e) => setO({ ...o, subtotal: e.target.value })} /></Field>
          <Field id="off-tax" label="Tax (minor units)"><input id="off-tax" inputMode="numeric" className={field} value={o.tax} onChange={(e) => setO({ ...o, tax: e.target.value })} /></Field>
          <button type="submit" className={primary} disabled={record.isPending || !o.tenant_id || !o.number || !o.subtotal}>Record</button>
        </form>
      )}
      <FormError error={record.error} />

      {q.isPending && <Loading what="Loading invoices" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && (
        <>
          <div className="overflow-x-auto rounded-lg border border-border bg-surface">
            <table className="w-full text-left text-[12px]">
              <thead className="bg-surface-neutral text-text-muted"><tr>
                <th className="px-3 py-2">Invoice</th><th className="px-3 py-2">Tenant</th><th className="px-3 py-2">Status</th>
                <th className="px-3 py-2 text-right">Total</th><th className="px-3 py-2 text-right">Refunded</th><th className="px-3 py-2 text-right">Actions</th>
              </tr></thead>
              <tbody className="divide-y divide-border/60">
                {q.data.data.length === 0 && <tr><td colSpan={6} className="px-3 py-6 text-center text-text-muted">No invoices match.</td></tr>}
                {q.data.data.map((i) => (
                  <tr key={i.id}>
                    <td className="px-3 py-1.5 font-mono">{i.number ?? i.stripe_id ?? '—'} <span className="text-text-muted">({i.source})</span></td>
                    <td className="px-3 py-1.5 font-mono text-text-muted">{i.tenant_id}</td>
                    <td className="px-3 py-1.5">{i.status}</td>
                    <td className="px-3 py-1.5 text-right font-mono">{formatMoney(i.total, i.currency)}</td>
                    <td className="px-3 py-1.5 text-right font-mono">{formatMoney(i.amount_refunded, i.currency)}</td>
                    <td className="px-3 py-1.5 text-right whitespace-nowrap">
                      {canManage && i.amount_refunded < i.total && (
                        <>
                          <button type="button" className="text-accent hover:underline cursor-pointer" onClick={() => { setTarget({ invoice: i, kind: 'refund' }); setAmount(''); setReason(''); }}>Refund</button>
                          {' · '}
                          <button type="button" className="text-accent hover:underline cursor-pointer" onClick={() => { setTarget({ invoice: i, kind: 'credit_note' }); setAmount(''); setReason(''); }}>Credit note</button>
                        </>
                      )}
                      {canManage && i.source === 'offline' && i.status !== 'paid' && (
                        <>{' · '}<button type="button" className="text-accent hover:underline cursor-pointer" onClick={() => markPaid.mutate(i.id)}>Mark paid</button></>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {q.data.meta.next_cursor && <div className="flex justify-center"><button type="button" className={secondary} onClick={() => setCursor(q.data?.meta.next_cursor ?? undefined)}>Load more</button></div>}
        </>
      )}
      <FormError error={markPaid.error} />

      {target && (
        <form className="grid grid-cols-1 gap-3 rounded-lg border border-border bg-surface p-3 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); refund.mutate(); }}>
          <Field id="rf-amount" label={`Amount (${target.invoice.currency}, minor units)`}><input id="rf-amount" inputMode="numeric" className={field} value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
          <Field id="rf-reason" label="Reason"><input id="rf-reason" className={field} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
          <div className="flex items-end gap-2">
            <button type="submit" className={primary} disabled={refund.isPending || !(Number(amount) >= 1) || reason.trim() === ''}>{refund.isPending ? 'Sending…' : `Request ${target.kind === 'refund' ? 'refund' : 'credit note'}`}</button>
            <button type="button" className={secondary} onClick={() => setTarget(null)}>Cancel</button>
          </div>
          <div className="sm:col-span-3"><FormError error={refund.error} /></div>
        </form>
      )}
    </div>
  );
};

const TaxTab: React.FC = () => {
  const [range, setRange] = useState({ from: firstOfMonth(), to: today() });
  const q = useQuery({ queryKey: ['admin', 'billing', 'tax', range], queryFn: () => getTaxReport(range.from, range.to) });
  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-2 items-end gap-2 sm:grid-cols-3">
        <Field id="tx-from" label="From"><input id="tx-from" type="date" className={field} value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /></Field>
        <Field id="tx-to" label="To"><input id="tx-to" type="date" className={field} value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></Field>
      </div>
      {q.isPending && <Loading what="Loading the tax report" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && (
        <table className="w-full text-left text-[12px]">
          <thead className="text-text-muted"><tr><th className="py-1">Country</th><th className="py-1">Currency</th><th className="py-1">Month</th>
            <th className="py-1 text-right">Net</th><th className="py-1 text-right">Tax</th><th className="py-1 text-right">Gross</th></tr></thead>
          <tbody className="divide-y divide-border/60">
            {q.data.length === 0 && <tr><td colSpan={6} className="py-4 text-center text-text-muted">No paid invoices in this range.</td></tr>}
            {q.data.map((r, i) => (
              <tr key={`${r.country}-${r.currency}-${r.month}-${i}`}>
                <td className="py-1">{r.country ?? '—'}</td><td className="py-1 font-mono">{r.currency}</td><td className="py-1 font-mono">{r.month}</td>
                <td className="py-1 text-right font-mono">{formatMoney(r.net, r.currency)}</td>
                <td className="py-1 text-right font-mono">{formatMoney(r.tax, r.currency)}</td>
                <td className="py-1 text-right font-mono">{formatMoney(r.gross, r.currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
};

const RevenueTab: React.FC = () => {
  const [month, setMonth] = useState(thisMonth());
  const q = useQuery({ queryKey: ['admin', 'billing', 'revenue', month], queryFn: () => getRevenueRecognition(month) });
  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-end gap-2">
        <Field id="rv-month" label="Month"><input id="rv-month" type="month" className={field} value={month} onChange={(e) => setMonth(e.target.value)} /></Field>
        <button type="button" className={secondary} onClick={() => void downloadCsv(`/billing/revenue-recognition.csv?month=${month}`, `revenue-recognition-${month}.csv`).catch(() => toast.error('Download failed'))}>Export CSV</button>
      </div>
      {q.isPending && <Loading what="Loading deferred revenue" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && (
        <table className="w-full text-left text-[12px]">
          <thead className="text-text-muted"><tr><th className="py-1">Invoice</th><th className="py-1">Tenant</th><th className="py-1">Description</th><th className="py-1">Plan</th><th className="py-1 text-right">Recognised</th></tr></thead>
          <tbody className="divide-y divide-border/60">
            {q.data.length === 0 && <tr><td colSpan={5} className="py-4 text-center text-text-muted">Nothing recognised in {month}.</td></tr>}
            {q.data.map((r, i) => (
              <tr key={`${r.invoice_id}-${i}`}>
                <td className="py-1 font-mono">{r.number ?? r.invoice_id}</td><td className="py-1 font-mono text-text-muted">{r.tenant_id}</td>
                <td className="py-1">{r.description}</td><td className="py-1 font-mono">{r.plan_code ?? '—'}</td>
                <td className="py-1 text-right font-mono">{formatMoney(r.recognised, r.currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
};

const PayoutsTab: React.FC = () => {
  const [range, setRange] = useState({ from: firstOfMonth(), to: today() });
  const q = useQuery({ queryKey: ['admin', 'billing', 'payouts', range], queryFn: () => getPayouts(range.from, range.to) });
  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-end gap-2">
        <Field id="po-from" label="From"><input id="po-from" type="date" className={field} value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /></Field>
        <Field id="po-to" label="To"><input id="po-to" type="date" className={field} value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></Field>
        <button type="button" className={secondary} onClick={() => void downloadCsv(`/billing/payouts.csv?from=${range.from}&to=${range.to}`, `payouts-${range.from}-${range.to}.csv`).catch(() => toast.error('Download failed'))}>Export CSV</button>
      </div>
      {q.isPending && <Loading what="Loading payouts" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && (
        <table className="w-full text-left text-[12px]">
          <thead className="text-text-muted"><tr><th className="py-1">Stripe payout</th><th className="py-1">Arrival</th><th className="py-1">Status</th><th className="py-1 text-right">Amount</th></tr></thead>
          <tbody className="divide-y divide-border/60">
            {q.data.length === 0 && <tr><td colSpan={4} className="py-4 text-center text-text-muted">No payouts in this range.</td></tr>}
            {q.data.map((p) => (
              <tr key={p.stripe_id}><td className="py-1 font-mono">{p.stripe_id}</td><td className="py-1 font-mono">{p.arrival_date ?? '—'}</td><td className="py-1">{p.status}</td>
                <td className="py-1 text-right font-mono">{formatMoney(p.amount, p.currency)}</td></tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
};

export const LiveBillingOps: React.FC = () => {
  const [tab, setTab] = useState<Tab>('invoices');
  const session = useAdminSession().data;
  const canManage = session?.can.includes('billing.refund.request') ?? false;
  const tabs = useMemo(() => [
    { id: 'invoices' as const, label: 'Invoices' }, { id: 'tax' as const, label: 'Tax' },
    { id: 'revenue' as const, label: 'Deferred revenue' }, { id: 'payouts' as const, label: 'Payouts' },
  ], []);

  return (
    <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 text-text-primary">
      <div className="flex items-center gap-2 border-b border-border pb-2">
        <h1 className="text-xl font-semibold">Billing operations</h1>
        <span className="text-[11px] text-text-muted">Mirrored from Stripe; refunds and credit notes need a second approver</span>
      </div>
      <div role="tablist" className="flex flex-wrap gap-1 border-b border-border text-xs">
        {tabs.map((t) => (
          <button key={t.id} type="button" role="tab" aria-selected={tab === t.id} onClick={() => setTab(t.id)}
            className={`px-3 py-2 border-b-2 -mb-px cursor-pointer ${tab === t.id ? 'border-accent text-text-primary font-medium' : 'border-transparent text-text-secondary hover:text-text-primary'}`}>
            {t.label}
          </button>
        ))}
      </div>
      {tab === 'invoices' && <InvoicesTab canManage={canManage} />}
      {tab === 'tax' && <TaxTab />}
      {tab === 'revenue' && <RevenueTab />}
      {tab === 'payouts' && <PayoutsTab />}
    </div>
  );
};
