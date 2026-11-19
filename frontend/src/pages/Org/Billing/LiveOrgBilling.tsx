/**
 * Tenant-side billing (10 §4, plan Task 24 / 4b.8). Reads the organisation's plan, usage meters, live subscription and
 * recent invoices through `GET /api/v1/org/billing` (owners and admins). It never fakes a payment method or a
 * self-service plan change that the API does not offer; support access and the data-export "coming soon" note are real.
 */
import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { formatDate, formatMoney } from '@/lib/format';
import { fetchBillingSummary } from '@/api/live/orgBilling';
import { fetchSupportAccess, grantSupportAccess, revokeSupportAccess } from '@/api/live/supportAccess';
import { useMe, useHomePath } from '@/data/session';
import { BrandMark } from '@/layouts/shell/Sidebar';
import { FormError, LoadError, Loading, secondary } from '@/components/live/form';
import { SettingsCard } from '@/pages/Settings/settings-ui';

const METER_LABELS: Record<string, string> = {
  companies: 'Companies', users: 'Users', api_clients: 'API clients', journal_lines_per_month: 'Journal lines / month',
  storage_mb: 'Storage (MB)', api_calls_per_day: 'API calls / day',
};

const Meter: React.FC<{ k: string; limit: number | null; used: number; ratio: number | null; level: string }> = ({ k, limit, used, ratio, level }) => {
  const pct = ratio === null ? 0 : Math.min(100, Math.round(ratio * 100));
  return (
    <div className="border border-border rounded-md p-3 bg-surface">
      <div className="flex items-baseline justify-between text-[13px]">
        <span className="font-medium">{METER_LABELS[k] ?? k}</span>
        <span className="font-mono">{used} / {limit === null ? 'Unlimited' : limit}</span>
      </div>
      {limit !== null && (
        <div className="mt-2 h-1.5 rounded bg-surface-neutral overflow-hidden">
          <div className={`h-full ${level === 'over' ? 'bg-danger' : level === 'warn' ? 'bg-warning' : 'bg-accent'}`} style={{ width: `${pct}%` }} />
        </div>
      )}
    </div>
  );
};

const SupportAccessCard: React.FC = () => {
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['org', 'support-access'], queryFn: fetchSupportAccess });
  const grant = useMutation({
    mutationFn: (hours: 24 | 72 | 168) => grantSupportAccess(hours),
    onSuccess: () => { toast.success('Support access granted.'); void qc.invalidateQueries({ queryKey: ['org', 'support-access'] }); },
  });
  const revoke = useMutation({
    mutationFn: () => revokeSupportAccess(),
    onSuccess: () => { toast.success('Support access closed. Any session in progress has ended.'); void qc.invalidateQueries({ queryKey: ['org', 'support-access'] }); },
  });
  const busy = grant.isPending || revoke.isPending;

  return (
    <SettingsCard title="Support access" description="Let Ledgerly support sign in on your behalf for a limited time. Every session is logged and shown with a banner.">
      {q.isPending ? <Loading what="Loading support access" /> : q.isError ? <LoadError error={q.error} onRetry={() => void q.refetch()} /> : (
        <div className="flex flex-col gap-3">
          {q.data?.open && q.data.grant ? (
            <>
              <p className="text-[13px] text-text-secondary">Open until <strong>{formatDate(q.data.grant.expires_at.slice(0, 10))}</strong> ({q.data.grant.hours} hours).</p>
              <div><button type="button" className={secondary} disabled={busy} onClick={() => revoke.mutate()}>{revoke.isPending ? 'Closing…' : 'Close support access'}</button></div>
            </>
          ) : (
            <>
              <p className="text-[13px] text-text-muted">Support access is closed. Open it only when you have asked us to help.</p>
              <div className="flex flex-wrap gap-2">
                {([24, 72, 168] as const).map((h) => (
                  <button key={h} type="button" className={secondary} disabled={busy} onClick={() => grant.mutate(h)}>{h === 168 ? 'Grant 7 days' : `Grant ${h} hours`}</button>
                ))}
              </div>
            </>
          )}
          <FormError error={grant.error ?? revoke.error} />
        </div>
      )}
    </SettingsCard>
  );
};

export const LiveOrgBilling: React.FC = () => {
  const me = useMe();
  const home = useHomePath();
  const q = useQuery({ queryKey: ['org', 'billing'], queryFn: fetchBillingSummary, retry: false });
  const [showExport, setShowExport] = useState(false);

  return (
    <div className="min-h-screen bg-canvas">
      <header className="flex h-14 items-center gap-2 border-b border-border bg-sidebar px-5">
        <BrandMark />
        <span className="text-[15px] font-semibold text-text-primary">Ledgerly</span>
        <Link to="/org/settings" className="ml-auto text-[13px] text-accent hover:underline">Organisation settings</Link>
        {home && <Link to={home} className="text-[13px] text-accent hover:underline">Back to your books</Link>}
      </header>
      <main className="mx-auto flex w-full max-w-4xl flex-col gap-5 px-4 py-6">
        <h1 className="text-xl font-semibold text-text-primary">Billing</h1>
        {me.isPending ? <Loading what="Loading billing" /> : q.isPending ? <Loading what="Loading your plan" /> : q.isError ? (
          <LoadError error={q.error} onRetry={() => void q.refetch()} />
        ) : (
          <>
            <SettingsCard title="Plan" description="Your current plan and how it bills.">
              {q.data.subscription ? (
                <dl className="grid grid-cols-[10rem_1fr] gap-y-1 text-[13px]">
                  <dt className="text-text-muted">Plan</dt><dd className="font-medium">{q.data.subscription.plan_name}</dd>
                  <dt className="text-text-muted">Status</dt><dd>{q.data.subscription.status}</dd>
                  <dt className="text-text-muted">Per month</dt><dd className="font-mono">{formatMoney(q.data.subscription.monthly_amount, q.data.subscription.currency)}</dd>
                  <dt className="text-text-muted">Interval</dt><dd>{q.data.subscription.interval === 'year' ? 'Annual' : 'Monthly'}</dd>
                  {q.data.subscription.trial_ends_at && <><dt className="text-text-muted">Trial ends</dt><dd>{formatDate(q.data.subscription.trial_ends_at.slice(0, 10))}</dd></>}
                  {q.data.subscription.current_period_end && <><dt className="text-text-muted">Period ends</dt><dd>{formatDate(q.data.subscription.current_period_end.slice(0, 10))}</dd></>}
                  {q.data.subscription.cancel_at && <><dt className="text-text-muted">Cancels on</dt><dd>{formatDate(q.data.subscription.cancel_at.slice(0, 10))}</dd></>}
                </dl>
              ) : (
                <p className="text-[13px] text-text-muted">You are on the {q.data.plan_code ?? 'default'} plan. Contact us to change plans, add seats or add-ons.</p>
              )}
              <p className="mt-3 text-[12px] text-text-muted">Changes to your plan, card details and cancellations are handled by our team. Write to us and we will confirm before anything is charged.</p>
            </SettingsCard>

            <SettingsCard title="Usage" description="What your plan includes this month. Warnings appear at 80 % and 100 % of a limit.">
              {q.data.meters.length === 0 ? <p className="text-[13px] text-text-muted">No plan limits apply.</p> : (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {q.data.meters.map((m) => <Meter key={m.key} k={m.key} limit={m.limit} used={m.used} ratio={m.ratio} level={m.level} />)}
                </div>
              )}
            </SettingsCard>

            <SettingsCard title="Invoices" description="Invoices raised for your subscription.">
              {q.data.invoices.length === 0 ? <p className="text-[13px] text-text-muted">No invoices yet.</p> : (
                <div className="overflow-x-auto">
                  <table className="w-full text-left text-[12px]">
                    <thead className="text-text-muted"><tr><th className="py-1">Number</th><th className="py-1">Status</th><th className="py-1">Date</th><th className="py-1 text-right">Total</th><th className="py-1 text-right">PDF</th></tr></thead>
                    <tbody className="divide-y divide-border/60">
                      {q.data.invoices.map((i) => (
                        <tr key={i.id}>
                          <td className="py-1.5 font-mono">{i.number ?? i.id}</td><td className="py-1.5">{i.status}</td>
                          <td className="py-1.5 font-mono">{formatDate((i.paid_at ?? i.created_at).slice(0, 10))}</td>
                          <td className="py-1.5 text-right font-mono">{formatMoney(i.total, i.currency)}</td>
                          <td className="py-1.5 text-right">{i.pdf_url ? <a href={i.pdf_url} target="_blank" rel="noreferrer" className="text-accent hover:underline">PDF</a> : '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </SettingsCard>

            <SupportAccessCard />

            <SettingsCard title="Leaving Ledgerly?" description="Export your data before you cancel.">
              <button type="button" className={secondary} onClick={() => setShowExport((v) => !v)}>Export my data</button>
              {showExport && <p className="mt-2 text-[13px] text-text-secondary">A self-service export is coming soon (Phase 4.7). Until then, ask support and we will prepare a complete archive, including your audit trail.</p>}
            </SettingsCard>

            <FormError error={q.error} />
          </>
        )}
      </main>
    </div>
  );
};
