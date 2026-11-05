/** Fund transfers, live (plan 3c.8): the list. The server holds every figure; this lists them in the account's own currency. */
import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { PageHeader } from '@/components/domain/PageHeader';
import { EmptyState } from '@/components/domain/EmptyState';
import { StatusPill } from '@/components/domain/StatusPill';
import { useRouteCompany } from '@/data/session';
import { useBankAccounts } from '@/data/banking';
import { useTxList } from '@/data/transactions';
import { legStateText, minor, type ApiFundTransfer } from '@/api/adapters/transactions';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { BTN_ACCENT, ErrorLine, LoadMore, LoadingLine, TableCard, inputClass } from '@/pages/Sales/live/parts';

const STATUSES = [
  { value: '', label: 'All' },
  { value: 'draft', label: 'Drafts' },
  { value: 'pending_approval', label: 'Waiting for approval' },
  { value: 'posted', label: 'Sent or received' },
  { value: 'reversed', label: 'Reversed' },
];

export const TransfersLive: React.FC = () => {
  const company = useRouteCompany();
  const navigate = useNavigate();
  const [status, setStatus] = useState('');
  const list = useTxList<ApiFundTransfer>('fund-transfers', { status });
  const banks = useBankAccounts();
  const bankName = (id: string) => banks.data.find((b) => b.id === id)?.name ?? 'Account';
  const base = `/${company.slug}/banking/transfers`;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        breadcrumb={[{ label: 'Bank accounts', to: `/${company.slug}/banking` }, { label: 'Transfers' }]}
        title="Transfers between accounts"
        subtitle="Money moved from one of your bank or cash accounts to another. While it is on its way it sits in funds in transit."
        actions={
          <button type="button" onClick={() => navigate(`${base}/new`)} className={BTN_ACCENT}>
            <Plus className="size-4" /> New transfer
          </button>
        }
      />
      <div className="flex items-center gap-2">
        <label htmlFor="tr-status" className="text-xs text-text-muted">Show</label>
        <select id="tr-status" value={status} onChange={(e) => setStatus(e.target.value)} className={cn(inputClass, 'w-52')}>
          {STATUSES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
        </select>
      </div>
      {list.isLoading ? (
        <LoadingLine what="transfers" />
      ) : list.error ? (
        <ErrorLine error={list.error} onRetry={list.refetch} />
      ) : list.rows.length === 0 ? (
        <EmptyState
          title={status ? 'No transfers match' : 'No transfers yet'}
          description="Record the money you move between your own accounts, even when it takes days to arrive."
          actionLabel="New transfer"
          onAction={() => navigate(`${base}/new`)}
        />
      ) : (
        <TableCard>
          <table className="w-full text-left text-[13px]">
            <thead>
              <tr className="border-b border-border text-[11px] font-semibold uppercase tracking-[0.04em] text-text-muted">
                <th className="px-4 py-2.5">Number</th>
                <th className="px-4 py-2.5">Sent</th>
                <th className="px-4 py-2.5">From and to</th>
                <th className="px-4 py-2.5 text-right">Amount sent</th>
                <th className="px-4 py-2.5">Where the money is</th>
                <th className="px-4 py-2.5">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {list.rows.map((t) => {
                const leg = legStateText(t);
                return (
                  <tr key={t.id} className="hover:bg-surface-neutral">
                    <td className="px-4 py-2.5">
                      <Link to={`${base}/${t.id}`} className="font-medium text-accent hover:underline">{t.number ?? 'Draft'}</Link>
                    </td>
                    <td className="px-4 py-2.5 tabular-nums text-text-secondary">{formatDate(t.send_date)}</td>
                    <td className="px-4 py-2.5 text-text-primary">{bankName(t.from_bank_account_id)} to {bankName(t.to_bank_account_id)}</td>
                    <td className="px-4 py-2.5 text-right tabular-nums">{formatMoney(minor(t.from_amount), t.from_amount.currency)}</td>
                    <td className={cn('px-4 py-2.5 text-xs', leg.tone === 'warn' ? 'font-medium text-warning' : 'text-text-secondary')}>
                      {t.status === 'posted' ? (t.leg_state === 'completed' ? `Received ${formatDate(t.received_on)}` : 'In transit') : '—'}
                    </td>
                    <td className="px-4 py-2.5"><StatusPill status={t.status} /></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          <LoadMore hasMore={list.hasMore} loading={list.isLoadingMore} onClick={list.loadMore} />
        </TableCard>
      )}
    </div>
  );
};
