/**
 * Live platform overview (10 §3.1, plan Task 19): MRR/ARR, movements, tenants, trials, revenue by plan, geography and
 * cohorts. Every figure is shown per currency — there is no blended total (G9). Anything the API does not provide
 * (health score, LTV/CAC) is not shown.
 */
import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { getOverview } from '@/api/live/platform/overview';
import { formatMoney } from '@/lib/format';
import { LoadError, Loading } from '@/components/live/form';

const Card: React.FC<{ label: string; value: React.ReactNode; sub?: React.ReactNode; tone?: string }> = ({ label, value, sub, tone }) => (
  <div className="rounded-lg border border-border bg-surface p-3">
    <div className="text-[11px] text-text-muted truncate">{label}</div>
    <div className={`mt-1 text-[20px] font-medium tabular-nums ${tone ?? 'text-text-primary'}`}>{value}</div>
    {sub && <div className="mt-0.5 text-[11px] text-text-secondary truncate">{sub}</div>}
  </div>
);

export const LiveOverview: React.FC = () => {
  const q = useQuery({ queryKey: ['admin', 'overview'], queryFn: () => getOverview(12), staleTime: 30_000 });
  if (q.isPending) return <div className="p-4"><Loading what="Loading the overview" /></div>;
  if (q.isError) return <div className="p-4"><LoadError error={q.error} onRetry={() => void q.refetch()} /></div>;
  const d = q.data;

  return (
    <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-5 p-4 text-text-primary">
      <div className="flex items-center gap-2 border-b border-border pb-2">
        <h1 className="text-xl font-semibold">Platform overview</h1>
        <span className="text-[11px] text-text-muted">Recurring revenue, retention and tenant lifecycle, per currency</span>
      </div>

      <section className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
        {d.mrr.map((m) => <Card key={`mrr-${m.currency}`} label={`MRR (${m.currency})`} value={formatMoney(m.amount, m.currency)} />)}
        {d.arr.map((m) => <Card key={`arr-${m.currency}`} label={`ARR (${m.currency})`} value={formatMoney(m.amount, m.currency)} sub="Run rate" />)}
      </section>

      <section className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Card label="Active tenants" value={d.active_tenants} sub={`${d.trials} on trial`} />
        <Card label="Trial conversion" value={`${(d.trial_conversion_bp / 100).toFixed(1)}%`} sub="Last 90 days" />
        <Card label="Signups today" value={d.signups_today} />
        <Card label="Open invoices overdue" value={d.failed_payments.count} tone={d.failed_payments.count > 0 ? 'text-warning' : undefined}
          sub={d.failed_payments.by_currency.map((m) => formatMoney(m.amount, m.currency)).join(' · ') || 'None'} />
      </section>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section className="rounded-lg border border-border bg-surface p-4">
          <h2 className="mb-2 text-sm font-semibold">MRR movements</h2>
          {d.movements.length === 0 ? <p className="text-[13px] text-text-muted">No movements recorded yet.</p> : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-[12px]">
                <thead className="text-text-muted"><tr>
                  <th className="py-1">Month</th><th className="py-1">Cur</th><th className="py-1 text-right">New</th><th className="py-1 text-right">Expansion</th>
                  <th className="py-1 text-right">Contraction</th><th className="py-1 text-right">Churn</th><th className="py-1 text-right">Reactivation</th>
                </tr></thead>
                <tbody className="divide-y divide-border/60">
                  {d.movements.map((m) => (
                    <tr key={`${m.month}-${m.currency}`}>
                      <td className="py-1">{m.month}</td><td className="py-1 font-mono">{m.currency}</td>
                      <td className="py-1 text-right font-mono">{formatMoney(m.new, m.currency)}</td>
                      <td className="py-1 text-right font-mono">{formatMoney(m.expansion, m.currency)}</td>
                      <td className="py-1 text-right font-mono">{formatMoney(m.contraction, m.currency)}</td>
                      <td className="py-1 text-right font-mono">{formatMoney(m.churn, m.currency)}</td>
                      <td className="py-1 text-right font-mono">{formatMoney(m.reactivation, m.currency)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section className="rounded-lg border border-border bg-surface p-4">
          <h2 className="mb-2 text-sm font-semibold">Revenue by plan</h2>
          {d.revenue_by_plan.length === 0 ? <p className="text-[13px] text-text-muted">No subscriptions yet.</p> : (
            <table className="w-full text-left text-[12px]">
              <thead className="text-text-muted"><tr><th className="py-1">Plan</th><th className="py-1">Currency</th><th className="py-1 text-right">MRR</th></tr></thead>
              <tbody className="divide-y divide-border/60">
                {d.revenue_by_plan.map((r) => (
                  <tr key={`${r.plan_code}-${r.currency}`}>
                    <td className="py-1">{r.plan_code}</td><td className="py-1 font-mono">{r.currency}</td>
                    <td className="py-1 text-right font-mono">{formatMoney(r.mrr, r.currency)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>

        <section className="rounded-lg border border-border bg-surface p-4">
          <h2 className="mb-2 text-sm font-semibold">Tenants by country</h2>
          {d.geography.length === 0 ? <p className="text-[13px] text-text-muted">No tenants yet.</p> : (
            <ul className="text-[12px]">
              {d.geography.map((g) => (
                <li key={g.country ?? 'unknown'} className="flex justify-between border-b border-border/60 py-1">
                  <span>{g.country ?? 'Not set'}</span><span className="font-mono">{g.tenants}</span>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="rounded-lg border border-border bg-surface p-4">
          <h2 className="mb-2 text-sm font-semibold">Cohort retention</h2>
          {d.cohorts.length === 0 ? <p className="text-[13px] text-text-muted">No cohorts yet.</p> : (
            <div className="overflow-x-auto">
              <table className="text-left text-[11px]">
                <thead className="text-text-muted"><tr><th className="py-1 pr-2">Cohort</th><th className="py-1 pr-2">Size</th><th className="py-1">Retained by month</th></tr></thead>
                <tbody>
                  {d.cohorts.map((c) => (
                    <tr key={c.cohort_month} className="border-t border-border/60">
                      <td className="py-1 pr-2 font-mono">{c.cohort_month}</td><td className="py-1 pr-2 font-mono">{c.size}</td>
                      <td className="py-1 font-mono text-text-secondary">{c.retained.join(' · ')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>

      <section className="flex flex-wrap gap-3 text-[12px] text-text-secondary">
        <span>Approvals waiting: <strong className="text-text-primary">{d.open_items.approvals}</strong></span>
        <span>GDPR requests open: <strong className="text-text-primary">{d.open_items.gdpr}</strong></span>
      </section>
    </div>
  );
};
