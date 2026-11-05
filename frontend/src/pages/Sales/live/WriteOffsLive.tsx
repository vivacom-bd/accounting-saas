/** Write-offs and recoveries after write-off, live (plan 3c.9, 3c.12a): the list. Amounts are in the invoice's own currency. */
import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { PageHeader } from '@/components/domain/PageHeader';
import { EmptyState } from '@/components/domain/EmptyState';
import { StatusPill } from '@/components/domain/StatusPill';
import { useRouteCompany } from '@/data/session';
import { useContactDirectory } from '@/data/contacts';
import { useTxList } from '@/data/transactions';
import { minor, type ApiRecovery, type ApiWriteOff } from '@/api/adapters/transactions';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { BTN_GHOST, ErrorLine, LoadMore, LoadingLine, TableCard, inputClass } from './parts';

const STATUSES = [
  { value: '', label: 'All' },
  { value: 'draft', label: 'Drafts' },
  { value: 'pending_approval', label: 'Waiting for approval' },
  { value: 'posted', label: 'In the books' },
  { value: 'reversed', label: 'Reversed' },
];

export const WriteOffsLive: React.FC = () => {
  const company = useRouteCompany();
  const navigate = useNavigate();
  const co = `/${company.slug}`;
  const [status, setStatus] = useState('');
  const writeOffs = useTxList<ApiWriteOff>('write-offs', { status });
  const recoveries = useTxList<ApiRecovery>('write-off-recoveries', { status });
  const customers = useContactDirectory('customer', true);
  const nameOf = (id: string) => customers.byId.get(id)?.name ?? 'Customer';

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Write-offs"
        subtitle="Debts you have given up on, and anything that came back afterwards. A write-off starts from the customer's invoice."
        actions={
          <button type="button" onClick={() => navigate(`${co}/invoices`)} className={BTN_GHOST}>
            Find an invoice
          </button>
        }
      />
      <div className="flex items-center gap-2">
        <label htmlFor="wo-status" className="text-xs text-text-muted">Show</label>
        <select id="wo-status" value={status} onChange={(e) => setStatus(e.target.value)} className={cn(inputClass, 'w-52')}>
          {STATUSES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
        </select>
      </div>

      {writeOffs.isLoading ? (
        <LoadingLine what="write-offs" />
      ) : writeOffs.error ? (
        <ErrorLine error={writeOffs.error} onRetry={writeOffs.refetch} />
      ) : writeOffs.rows.length === 0 ? (
        <EmptyState title={status ? 'No write-offs match' : 'No write-offs yet'} description="Open a posted customer invoice and choose Write off." />
      ) : (
        <TableCard>
          <table className="w-full text-left text-[13px]" aria-label="Write-offs">
            <thead>
              <tr className="border-b border-border text-[11px] font-semibold uppercase tracking-[0.04em] text-text-muted">
                <th className="px-4 py-2.5">Number</th>
                <th className="px-4 py-2.5">Date</th>
                <th className="px-4 py-2.5">Customer</th>
                <th className="px-4 py-2.5 text-right">Written off</th>
                <th className="px-4 py-2.5 text-right">Recovered since</th>
                <th className="px-4 py-2.5">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {writeOffs.rows.map((w) => (
                <tr key={w.id} className="hover:bg-surface-neutral">
                  <td className="px-4 py-2.5"><Link to={`${co}/write-offs/${w.id}`} className="font-medium text-accent hover:underline">{w.number ?? 'Draft'}</Link></td>
                  <td className="px-4 py-2.5 tabular-nums text-text-secondary">{formatDate(w.write_off_date)}</td>
                  <td className="px-4 py-2.5">{nameOf(w.contact_id)}</td>
                  <td className="px-4 py-2.5 text-right tabular-nums">{formatMoney(minor(w.amount), w.amount.currency)}</td>
                  <td className="px-4 py-2.5 text-right tabular-nums text-text-secondary">{minor(w.recovered) > 0 ? formatMoney(minor(w.recovered), w.recovered.currency) : '—'}</td>
                  <td className="px-4 py-2.5"><StatusPill status={w.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
          <LoadMore hasMore={writeOffs.hasMore} loading={writeOffs.isLoadingMore} onClick={writeOffs.loadMore} />
        </TableCard>
      )}

      {recoveries.rows.length > 0 && (
        <section className="flex flex-col gap-3" aria-label="Recoveries after write-off">
          <h2 className="text-[13px] font-semibold text-text-primary">Recoveries after write-off</h2>
          <TableCard>
            <table className="w-full text-left text-[13px]">
              <thead>
                <tr className="border-b border-border text-[11px] font-semibold uppercase tracking-[0.04em] text-text-muted">
                  <th className="px-4 py-2.5">Number</th>
                  <th className="px-4 py-2.5">Date</th>
                  <th className="px-4 py-2.5">Customer</th>
                  <th className="px-4 py-2.5 text-right">Recovered</th>
                  <th className="px-4 py-2.5">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {recoveries.rows.map((r) => (
                  <tr key={r.id} className="hover:bg-surface-neutral">
                    <td className="px-4 py-2.5"><Link to={`${co}/write-off-recoveries/${r.id}`} className="font-medium text-accent hover:underline">{r.number ?? 'Draft'}</Link></td>
                    <td className="px-4 py-2.5 tabular-nums text-text-secondary">{formatDate(r.recovery_date)}</td>
                    <td className="px-4 py-2.5">{nameOf(r.contact_id)}</td>
                    <td className="px-4 py-2.5 text-right tabular-nums">{formatMoney(minor(r.amount), r.amount.currency)}</td>
                    <td className="px-4 py-2.5"><StatusPill status={r.status} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
            <LoadMore hasMore={recoveries.hasMore} loading={recoveries.isLoadingMore} onClick={recoveries.loadMore} />
          </TableCard>
        </section>
      )}
    </div>
  );
};
