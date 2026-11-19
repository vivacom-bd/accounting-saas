/**
 * Live trials and growth (10 §3.6, plan Task 16): the activation funnel, the current trials with their milestones, the
 * nudge switch, and a quoted conversion that closes a trial with a negotiated amount. Only data the API provides is
 * shown (no invented time-to-value figures).
 */
import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { convertWithQuote, getFunnel, getTrials, setNudges, type GrowthTrial } from '@/api/live/platform/growth';
import { tenantsApi } from '@/api/live/platform/tenants';
import type { PlanOption } from '@/api/adapters/platform/tenants';
import { Field, FormError, LoadError, Loading, field, primary, secondary } from '@/components/live/form';

const Card: React.FC<{ label: string; value: React.ReactNode; sub?: string }> = ({ label, value, sub }) => (
  <div className="rounded-lg border border-border bg-surface p-3">
    <div className="text-[11px] text-text-muted">{label}</div>
    <div className="mt-1 text-[20px] font-medium tabular-nums">{value}</div>
    {sub && <div className="mt-0.5 text-[11px] text-text-secondary">{sub}</div>}
  </div>
);

const ConvertForm: React.FC<{ trial: GrowthTrial; plans: PlanOption[]; onDone: () => void }> = ({ trial, plans, onDone }) => {
  const qc = useQueryClient();
  const [f, setF] = useState({ plan_code: '', interval: 'month' as 'month' | 'year', currency: 'USD', custom_amount: '', collection: 'offline' as 'offline' | 'stripe', note: '' });
  const run = useMutation({
    mutationFn: () => convertWithQuote(trial.id, { plan_code: f.plan_code, interval: f.interval, currency: f.currency, custom_amount: Number(f.custom_amount), collection: f.collection, note: f.note.trim() || undefined }),
    onSuccess: () => { toast.success('Trial converted.'); void qc.invalidateQueries({ queryKey: ['admin', 'growth'] }); onDone(); },
  });
  return (
    <form className="grid grid-cols-2 items-end gap-2 rounded-lg border border-border bg-surface p-3 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); run.mutate(); }}>
      <Field id={`cv-plan-${trial.id}`} label="Plan"><select id={`cv-plan-${trial.id}`} className={field} value={f.plan_code} onChange={(e) => setF({ ...f, plan_code: e.target.value })}>
        <option value="">Choose</option>{plans.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
      </select></Field>
      <Field id={`cv-int-${trial.id}`} label="Interval"><select id={`cv-int-${trial.id}`} className={field} value={f.interval} onChange={(e) => setF({ ...f, interval: e.target.value as 'month' | 'year' })}>
        <option value="month">Monthly</option><option value="year">Annual</option>
      </select></Field>
      <Field id={`cv-cur-${trial.id}`} label="Currency"><select id={`cv-cur-${trial.id}`} className={field} value={f.currency} onChange={(e) => setF({ ...f, currency: e.target.value })}>
        {['USD', 'KES', 'EUR'].map((c) => <option key={c} value={c}>{c}</option>)}
      </select></Field>
      <Field id={`cv-amt-${trial.id}`} label="Custom amount (minor units)"><input id={`cv-amt-${trial.id}`} inputMode="numeric" className={field} value={f.custom_amount} onChange={(e) => setF({ ...f, custom_amount: e.target.value })} /></Field>
      <Field id={`cv-col-${trial.id}`} label="Collection"><select id={`cv-col-${trial.id}`} className={field} value={f.collection} onChange={(e) => setF({ ...f, collection: e.target.value as 'offline' | 'stripe' })}>
        <option value="offline">Invoiced offline</option><option value="stripe">Stripe</option>
      </select></Field>
      <Field id={`cv-note-${trial.id}`} label="Note"><input id={`cv-note-${trial.id}`} className={field} value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} /></Field>
      <div className="flex items-end gap-2">
        <button type="submit" className={primary} disabled={run.isPending || !f.plan_code || f.custom_amount === ''}>{run.isPending ? 'Converting…' : 'Convert'}</button>
        <button type="button" className={secondary} onClick={onDone}>Cancel</button>
      </div>
      <div className="col-span-2 sm:col-span-4"><FormError error={run.error} /></div>
    </form>
  );
};

export const LiveGrowth: React.FC = () => {
  const [converting, setConverting] = useState<string | null>(null);
  const [nudgesOn, setNudgesOn] = useState(true);
  const funnel = useQuery({ queryKey: ['admin', 'growth', 'funnel'], queryFn: () => getFunnel() });
  const trials = useQuery({ queryKey: ['admin', 'growth', 'trials'], queryFn: getTrials });
  const plans = useQuery({ queryKey: ['admin', 'plans', 'options'], queryFn: () => tenantsApi.plans(), staleTime: 60_000 });
  const toggle = useMutation({ mutationFn: (enabled: boolean) => setNudges(enabled), onSuccess: (r) => { setNudgesOn(r.enabled); toast.success(r.enabled ? 'Nudges on.' : 'Nudges off.'); } });

  return (
    <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 text-text-primary">
      <div className="flex flex-wrap items-center gap-2 border-b border-border pb-2">
        <h1 className="text-xl font-semibold">Trials and growth</h1>
        <button type="button" className={`ml-auto ${secondary}`} disabled={toggle.isPending} onClick={() => toggle.mutate(!nudgesOn)}>
          {nudgesOn ? 'Turn nudges off' : 'Turn nudges on'}
        </button>
      </div>
      <FormError error={toggle.error} />

      {funnel.isPending && <Loading what="Loading the funnel" />}
      {funnel.isError && <LoadError error={funnel.error} onRetry={() => void funnel.refetch()} />}
      {funnel.data && (
        <section className="grid grid-cols-2 gap-3 md:grid-cols-5">
          <Card label="Signups" value={funnel.data.signups} />
          <Card label="Created a company" value={funnel.data.created_company} />
          <Card label="Posted a journal" value={funnel.data.posted_first_journal} />
          <Card label="Converted" value={funnel.data.converted} />
          <Card label="Conversion" value={`${(funnel.data.conversion_rate_bp / 100).toFixed(1)}%`} />
        </section>
      )}

      <section className="flex flex-col gap-2">
        <h2 className="text-[15px] font-semibold">Current trials</h2>
        {trials.isPending && <Loading what="Loading trials" />}
        {trials.isError && <LoadError error={trials.error} onRetry={() => void trials.refetch()} />}
        {trials.data && trials.data.length === 0 && <p className="text-[13px] text-text-muted">No organisations on trial right now.</p>}
        {trials.data && trials.data.length > 0 && (
          <div className="overflow-x-auto rounded-lg border border-border bg-surface">
            <table className="w-full text-left text-[12px]">
              <thead className="bg-surface-neutral text-text-muted"><tr>
                <th className="px-3 py-2">Organisation</th><th className="px-3 py-2">Owner</th>
                <th className="px-3 py-2">Company</th><th className="px-3 py-2">Posted</th><th className="px-3 py-2 text-right">Members</th>
                <th className="px-3 py-2 text-right">Days left</th><th className="px-3 py-2 text-right">Activation</th><th className="px-3 py-2" />
              </tr></thead>
              <tbody className="divide-y divide-border/60">
                {trials.data.map((t) => (
                  <React.Fragment key={t.id}>
                    <tr>
                      <td className="px-3 py-1.5"><div className="font-medium">{t.name}</div><div className="font-mono text-[11px] text-text-muted">{t.slug}</div></td>
                      <td className="px-3 py-1.5 text-text-secondary">{t.owner_email ?? '—'}</td>
                      <td className="px-3 py-1.5">{t.has_company ? 'Yes' : '—'}</td>
                      <td className="px-3 py-1.5">{t.has_posted ? 'Yes' : '—'}</td>
                      <td className="px-3 py-1.5 text-right font-mono">{t.members}</td>
                      <td className={`px-3 py-1.5 text-right font-mono ${t.days_left !== null && t.days_left <= 3 ? 'text-warning' : ''}`}>{t.days_left ?? '—'}</td>
                      <td className="px-3 py-1.5 text-right font-mono">{t.activation_score}%</td>
                      <td className="px-3 py-1.5 text-right"><button type="button" className="text-accent hover:underline cursor-pointer" onClick={() => setConverting(converting === t.id ? null : t.id)}>Convert</button></td>
                    </tr>
                    {converting === t.id && (
                      <tr><td colSpan={8} className="px-3 py-2"><ConvertForm trial={t} plans={plans.data ?? []} onDone={() => setConverting(null)} /></td></tr>
                    )}
                  </React.Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
};
