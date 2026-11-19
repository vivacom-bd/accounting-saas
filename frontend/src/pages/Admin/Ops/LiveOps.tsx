/**
 * Live platform operations (10 §3.9, plan Task 17): database/redis/queue health, outbox backlog, scheduler and seal
 * heartbeat, mail volume, maintenance mode, and tenant rate-limit overrides. Only what the API measures is shown.
 */
import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { clearRateLimit, getHealth, getMaintenance, getRateLimit, setMaintenance, setRateLimit } from '@/api/live/platform/ops';
import { formatDateTime } from '@/lib/format';
import { Field, FormError, LoadError, Loading, field, primary, secondary } from '@/components/live/form';

const dot = (ok: boolean) => (ok ? 'bg-success' : 'bg-danger');
const Panel: React.FC<{ title: string; status: string; children?: React.ReactNode }> = ({ title, status, children }) => (
  <div className="rounded-lg border border-border bg-surface p-3">
    <div className="flex items-center justify-between">
      <span className="text-[13px] font-medium">{title}</span>
      <span className="flex items-center gap-1 text-[11px] text-text-secondary"><span className={`h-1.5 w-1.5 rounded-full ${dot(status === 'ok')}`} />{status}</span>
    </div>
    {children && <div className="mt-2 text-[12px] text-text-secondary">{children}</div>}
  </div>
);

const RateLimit: React.FC = () => {
  const qc = useQueryClient();
  const [tenant, setTenant] = useState('');
  const [applied, setApplied] = useState('');
  const [f, setF] = useState({ per_minute: '', reason: '', expires_at: '' });
  const current = useQuery({ queryKey: ['admin', 'ops', 'rate-limit', applied], queryFn: () => getRateLimit(applied), enabled: applied !== '' });
  const save = useMutation({
    mutationFn: () => setRateLimit(applied, { per_minute: Number(f.per_minute), reason: f.reason.trim(), expires_at: f.expires_at || null }),
    onSuccess: () => { toast.success('Rate limit saved.'); void qc.invalidateQueries({ queryKey: ['admin', 'ops', 'rate-limit', applied] }); },
  });
  const clear = useMutation({ mutationFn: () => clearRateLimit(applied), onSuccess: () => { toast.success('Override cleared.'); void qc.invalidateQueries({ queryKey: ['admin', 'ops', 'rate-limit', applied] }); } });
  return (
    <section className="flex flex-col gap-2">
      <h2 className="text-[15px] font-semibold">Tenant rate-limit override</h2>
      <form className="flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); setApplied(tenant.trim()); }}>
        <Field id="rl-tenant" label="Tenant id"><input id="rl-tenant" className={field} value={tenant} onChange={(e) => setTenant(e.target.value)} /></Field>
        <button type="submit" className={secondary}>Show</button>
      </form>
      {applied && (
        <>
          {current.isPending && <Loading what="Loading the override" />}
          {current.isError && <LoadError error={current.error} onRetry={() => void current.refetch()} />}
          {current.data === null && <p className="text-[13px] text-text-muted">No override; the default applies.</p>}
          {current.data && <p className="text-[13px] text-text-secondary">Now: <span className="font-mono">{current.data.per_minute}</span> / minute — {current.data.reason}</p>}
          <form className="grid grid-cols-2 items-end gap-2 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); save.mutate(); }}>
            <Field id="rl-per" label="Per minute"><input id="rl-per" inputMode="numeric" className={field} value={f.per_minute} onChange={(e) => setF({ ...f, per_minute: e.target.value })} /></Field>
            <Field id="rl-reason" label="Reason"><input id="rl-reason" className={field} value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} /></Field>
            <Field id="rl-exp" label="Expires (optional)"><input id="rl-exp" type="date" className={field} value={f.expires_at} onChange={(e) => setF({ ...f, expires_at: e.target.value })} /></Field>
            <div className="flex gap-2">
              <button type="submit" className={primary} disabled={save.isPending || !(Number(f.per_minute) >= 1) || f.reason.trim() === ''}>Save</button>
              <button type="button" className={secondary} onClick={() => clear.mutate()} disabled={clear.isPending}>Clear</button>
            </div>
          </form>
          <FormError error={save.error ?? clear.error} />
        </>
      )}
    </section>
  );
};

export const LiveOps: React.FC = () => {
  const health = useQuery({ queryKey: ['admin', 'ops', 'health'], queryFn: getHealth, refetchInterval: 30_000 });
  const maintenance = useQuery({ queryKey: ['admin', 'ops', 'maintenance'], queryFn: getMaintenance });
  const [form, setForm] = useState<{ enabled: boolean; message: string; ends_at: string } | null>(null);
  const save = useMutation({
    mutationFn: () => setMaintenance({ enabled: form!.enabled, message: form!.message.trim() || null, ends_at: form!.ends_at || null }),
    onSuccess: () => { toast.success('Maintenance mode saved.'); setForm(null); void maintenance.refetch(); },
  });

  const current = form ?? (maintenance.data ? { enabled: maintenance.data.enabled, message: maintenance.data.message ?? '', ends_at: maintenance.data.ends_at ?? '' } : null);

  return (
    <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 text-text-primary">
      <div className="flex items-center gap-2 border-b border-border pb-2"><h1 className="text-xl font-semibold">Platform telemetry</h1></div>

      {health.isPending && <Loading what="Probing the platform" />}
      {health.isError && <LoadError error={health.error} onRetry={() => void health.refetch()} />}
      {health.data && (
        <section className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
          <Panel title="Database" status={health.data.database} />
          <Panel title="Redis" status={health.data.redis} />
          <Panel title="Queue" status={health.data.queue.status}>pending {health.data.queue.pending ?? '—'} · failed {health.data.queue.failed ?? '—'}</Panel>
          <Panel title="Outbox backlog" status={health.data.outbox_backlog.status}>
            {health.data.outbox_backlog.pending ?? '—'} pending{health.data.outbox_backlog.oldest_at ? ` · oldest ${formatDateTime(health.data.outbox_backlog.oldest_at)}` : ''}
          </Panel>
          <Panel title="Scheduler heartbeat" status={health.data.scheduler_last_beat.status}>{health.data.scheduler_last_beat.at ? formatDateTime(health.data.scheduler_last_beat.at) : '—'}</Panel>
          <Panel title="Audit seal" status={health.data.audit_seal_last_run.status}>{health.data.audit_seal_last_run.at ? formatDateTime(health.data.audit_seal_last_run.at) : '—'}</Panel>
          <Panel title="Mail sent (24h)" status="ok">{health.data.mail_sent_24h ?? '—'}</Panel>
        </section>
      )}

      <section className="flex flex-col gap-2 rounded-lg border border-border bg-surface p-4">
        <h2 className="text-[15px] font-semibold">Maintenance mode</h2>
        {maintenance.isPending && <Loading what="Loading maintenance state" />}
        {maintenance.isError && <LoadError error={maintenance.error} onRetry={() => void maintenance.refetch()} />}
        {current && (
          <form className="grid grid-cols-1 items-end gap-2 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); save.mutate(); }}>
            <label className="flex items-center gap-2 text-[13px]">
              <input type="checkbox" checked={current.enabled} onChange={(e) => setForm({ ...current, enabled: e.target.checked })} />
              Maintenance mode on
            </label>
            <Field id="mt-msg" label="Message"><input id="mt-msg" className={field} value={current.message} onChange={(e) => setForm({ ...current, message: e.target.value })} /></Field>
            <Field id="mt-ends" label="Ends (optional)"><input id="mt-ends" type="datetime-local" className={field} value={current.ends_at} onChange={(e) => setForm({ ...current, ends_at: e.target.value })} /></Field>
            <div className="sm:col-span-3"><button type="submit" className={primary} disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save maintenance state'}</button></div>
            <div className="sm:col-span-3"><FormError error={save.error} /></div>
          </form>
        )}
      </section>

      <RateLimit />
    </div>
  );
};
