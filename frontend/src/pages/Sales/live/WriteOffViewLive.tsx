/**
 * Write-off and recovery pages, live (plan 3c.9, 3c.12a). The write-off page shows how the amount splits between the allowance and bad-debt
 * expense, the server's note on tax, the recoveries against it, and the workflow card. The recovery page says what happens next: the
 * money arrives through a normal receipt. Every figure is the server's.
 */
import React from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Receipt, Undo2 } from 'lucide-react';
import { PageHeader } from '@/components/domain/PageHeader';
import { StatusPill } from '@/components/domain/StatusPill';
import { TxWorkflowCard } from '@/components/live/TxWorkflowCard';
import { TxPreviewPanel } from '@/components/live/TxPreviewPanel';
import { BTN_SECONDARY, CARD } from '@/pages/Sales/invoice-view/shared';
import { useRouteCompany } from '@/data/session';
import { useContactDirectory } from '@/data/contacts';
import { useDoc } from '@/data/invoices';
import { useTx, useTxList } from '@/data/transactions';
import { ApiError } from '@/api/errors';
import { minor, toTxCan, type ApiRecovery, type ApiWriteOff } from '@/api/adapters/transactions';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ErrorLine, LoadingLine } from './parts';

const Row: React.FC<{ label: string; children: React.ReactNode }> = ({ label, children }) => (
  <>
    <dt className="text-text-muted">{label}</dt>
    <dd className="tabular-nums text-text-primary">{children}</dd>
  </>
);

export const WriteOffViewLive: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const company = useRouteCompany();
  const co = `/${company.slug}`;
  const q = useTx<ApiWriteOff>('write-offs', id);
  const w = q.data;
  const recoveries = useTxList<ApiRecovery>('write-off-recoveries', { write_off_id: id ?? '' });
  const invoice = useDoc(w?.invoice_id);
  const customers = useContactDirectory('customer', true);

  if (q.isLoading) return <LoadingLine what="write-off" />;
  if (q.error || !w) {
    const missing = q.error instanceof ApiError && q.error.status === 404;
    return missing ? <p className="p-4 text-[13px] text-text-secondary">This write-off does not exist in this company.</p> : <ErrorLine error={q.error} onRetry={q.refetch} />;
  }
  const base = w.base_amount.currency;
  const can = toTxCan(w.can);
  const customer = customers.byId.get(w.contact_id);
  const recovered = minor(w.recovered);

  return (
    <div className="flex flex-col gap-6 pb-20">
      <PageHeader
        breadcrumb={[{ label: 'Write-offs', to: `${co}/write-offs` }, { label: w.number ?? 'Draft' }]}
        title={w.number ?? 'Draft write-off'}
        subtitle={customer?.name}
        meta={<StatusPill status={w.status} />}
      />
      {w.status === 'reversed' && (
        <div className="flex items-center gap-2 rounded-lg border border-border bg-surface-neutral px-4 py-3 text-[13px] font-medium text-text-secondary">
          This write-off was reversed, so the invoice owes the amount again.
          {w.reversal_journal_id && <Link className="underline" to={`${co}/journals/${w.reversal_journal_id}`}>View the reversing entry</Link>}
        </div>
      )}

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="flex flex-col gap-6">
          <section className={cn(CARD, 'p-6')} aria-label="Write-off details">
            <dl className="grid grid-cols-[180px_1fr] gap-x-4 gap-y-2 text-[13px]">
              <Row label="Invoice">
                <Link to={`${co}/invoices/${w.invoice_id}`} className="text-accent hover:underline">{invoice.data?.number ?? 'Open the invoice'}</Link>
              </Row>
              <Row label="Date">{formatDate(w.write_off_date)}</Row>
              <Row label="Written off">{formatMoney(minor(w.amount), w.amount.currency)}</Row>
              {w.amount.currency !== base && <Row label={`In ${base}`}>{formatMoney(minor(w.base_amount), base)}</Row>}
              {w.status === 'posted' || w.status === 'reversed' ? (
                <>
                  <Row label="Taken from the allowance">{formatMoney(minor(w.allowance_used), base)}</Row>
                  <Row label="Charged to bad-debt expense">{formatMoney(minor(w.expense), base)}</Row>
                </>
              ) : null}
              {w.status === 'posted' && <Row label="Recovered since">{formatMoney(recovered, w.recovered.currency)}</Row>}
              <Row label="Why"><span className="whitespace-pre-wrap">{w.reason}</span></Row>
              {w.reference && <Row label="Reference">{w.reference}</Row>}
            </dl>
            <p className="mt-4 rounded-md bg-surface-neutral p-3 text-xs text-text-secondary" data-testid="vat-note">{w.vat_note}</p>
            {w.journal_id && <Link className="mt-3 inline-block text-xs text-accent hover:underline" to={`${co}/journals/${w.journal_id}`}>View the journal entry</Link>}
          </section>

          {(w.status === 'draft' || w.status === 'pending_approval') && <TxPreviewPanel family="write-offs" id={w.id} version={w.version} />}

          {recoveries.rows.length > 0 && (
            <section className={cn(CARD, 'p-5')} aria-label="Recoveries">
              <h2 className="text-[13px] font-semibold text-text-primary">Recoveries against this write-off</h2>
              <ul className="mt-3 flex flex-col divide-y divide-border text-[13px]">
                {recoveries.rows.map((r) => (
                  <li key={r.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2.5">
                    <Link to={`${co}/write-off-recoveries/${r.id}`} className="font-medium text-accent hover:underline">{r.number ?? 'Draft'}</Link>
                    <span className="text-text-muted">{formatDate(r.recovery_date)}</span>
                    <StatusPill status={r.status} />
                    <span className="ml-auto tabular-nums">{formatMoney(minor(r.amount), r.amount.currency)}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>

        <aside className="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
          <TxWorkflowCard
            family="write-offs"
            noun="write-off"
            title="Write-off"
            doc={{ id: w.id, number: w.number, status: w.status, version: w.version, created_by: w.created_by, submitted_by: w.submitted_by, can }}
            headline={{ label: 'Written off', amount: minor(w.amount), currency: w.amount.currency }}
            signAmount={minor(w.base_amount)}
            signCurrency={base}
            reverseText="Reversing adds an equal and opposite entry and gives the invoice its open amount back. If part of it has been recovered, undo that recovery first."
            postText="The invoice stops being owed; the allowance is used first, then bad-debt expense."
            editTo={`${co}/write-offs/new?draft=${w.id}`}
            onReload={() => void q.refetch()}
            onDiscarded={() => navigate(`${co}/write-offs`)}
            postedExtras={
              can.recover ? (
                <button type="button" onClick={() => navigate(`${co}/write-off-recoveries/new?writeOff=${w.id}`)} className="flex h-8 cursor-pointer items-center justify-center gap-1.5 rounded-md bg-accent px-3 text-[13px] font-medium text-white transition hover:bg-accent-hover">
                  <Undo2 className="size-4" /> Record a recovery
                </button>
              ) : null
            }
          />
        </aside>
      </div>
    </div>
  );
};

export const RecoveryViewLive: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const company = useRouteCompany();
  const co = `/${company.slug}`;
  const q = useTx<ApiRecovery>('write-off-recoveries', id);
  const r = q.data;
  const w = useTx<ApiWriteOff>('write-offs', r?.write_off_id);
  const invoice = useDoc(r?.invoice_id);
  const customers = useContactDirectory('customer', true);

  if (q.isLoading) return <LoadingLine what="recovery" />;
  if (q.error || !r) {
    const missing = q.error instanceof ApiError && q.error.status === 404;
    return missing ? <p className="p-4 text-[13px] text-text-secondary">This recovery does not exist in this company.</p> : <ErrorLine error={q.error} onRetry={q.refetch} />;
  }
  const base = r.base_amount.currency;
  const can = toTxCan(r.can);
  const customer = customers.byId.get(r.contact_id);
  const inv = invoice.data;

  return (
    <div className="flex flex-col gap-6 pb-20">
      <PageHeader
        breadcrumb={[{ label: 'Write-offs', to: `${co}/write-offs` }, { label: w.data?.number ?? 'Write-off', to: `${co}/write-offs/${r.write_off_id}` }, { label: r.number ?? 'Draft recovery' }]}
        title={r.number ?? 'Draft recovery'}
        subtitle={customer?.name}
        meta={<StatusPill status={r.status} />}
      />
      {r.status === 'reversed' && (
        <div className="flex items-center gap-2 rounded-lg border border-border bg-surface-neutral px-4 py-3 text-[13px] font-medium text-text-secondary">
          This recovery was reversed, so the invoice is written off again for this amount.
          {r.reversal_journal_id && <Link className="underline" to={`${co}/journals/${r.reversal_journal_id}`}>View the reversing entry</Link>}
        </div>
      )}
      {r.status === 'posted' && inv && (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-accent/30 bg-accent-soft px-4 py-3 text-[13px] text-text-primary" data-testid="recovery-next">
          <span>
            The invoice owes {formatMoney(inv.amount_due_fc, inv.currency)} again. When the money arrives, record it as a normal receipt against {inv.number}.
          </span>
          {inv.amount_due_fc > 0 && (
            <button type="button" onClick={() => navigate(`${co}/receipts/new?contactId=${inv.contact_id}&invoiceId=${inv.id}`)} className="ml-auto flex h-8 items-center gap-1.5 rounded-md bg-accent px-3 text-[13px] font-medium text-white hover:bg-accent-hover">
              <Receipt className="size-4" /> Record the receipt
            </button>
          )}
        </div>
      )}

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="flex flex-col gap-6">
          <section className={cn(CARD, 'p-6')} aria-label="Recovery details">
            <dl className="grid grid-cols-[180px_1fr] gap-x-4 gap-y-2 text-[13px]">
              <Row label="Write-off"><Link to={`${co}/write-offs/${r.write_off_id}`} className="text-accent hover:underline">{w.data?.number ?? 'Open the write-off'}</Link></Row>
              <Row label="Invoice"><Link to={`${co}/invoices/${r.invoice_id}`} className="text-accent hover:underline">{inv?.number ?? 'Open the invoice'}</Link></Row>
              <Row label="Date">{formatDate(r.recovery_date)}</Row>
              <Row label="Recovered">{formatMoney(minor(r.amount), r.amount.currency)}</Row>
              {r.amount.currency !== base && <Row label={`In ${base}`}>{formatMoney(minor(r.base_amount), base)}</Row>}
              <Row label="How it came back"><span className="whitespace-pre-wrap">{r.reason}</span></Row>
              {r.reference && <Row label="Reference">{r.reference}</Row>}
            </dl>
            {r.journal_id && <Link className="mt-3 inline-block text-xs text-accent hover:underline" to={`${co}/journals/${r.journal_id}`}>View the journal entry</Link>}
          </section>
          {(r.status === 'draft' || r.status === 'pending_approval') && <TxPreviewPanel family="write-off-recoveries" id={r.id} version={r.version} />}
        </div>
        <aside className="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
          <TxWorkflowCard
            family="write-off-recoveries"
            noun="recovery"
            title="Recovery"
            doc={{ id: r.id, number: r.number, status: r.status, version: r.version, created_by: r.created_by, submitted_by: r.submitted_by, can }}
            headline={{ label: 'Recovered', amount: minor(r.amount), currency: r.amount.currency }}
            signAmount={minor(r.base_amount)}
            signCurrency={base}
            reverseText="Reversing adds an equal and opposite entry and writes the invoice off again for this amount. It is refused if a receipt has already used the reopened amount."
            postText="The debt is put back on the customer's account as a bad debt recovered, and the invoice reopens for this amount."
            editTo={`${co}/write-off-recoveries/new?draft=${r.id}`}
            onReload={() => void q.refetch()}
            onDiscarded={() => navigate(`${co}/write-offs/${r.write_off_id}`)}
          >
            {r.status === 'posted' && <Link to={`${co}/write-offs/${r.write_off_id}`} className={cn(BTN_SECONDARY, 'w-full')}>Back to the write-off</Link>}
          </TxWorkflowCard>
        </aside>
      </div>
    </div>
  );
};
