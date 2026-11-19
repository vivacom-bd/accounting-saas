/**
 * Live feature flags (10 §3.10, plan Task 17): the global switch and per-tenant overrides. There is no percentage
 * rollout in the API, so it is not shown. `Default` clears the stored switch; an override wins over the global value.
 */
import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { listFlags, setFlagForTenant, setFlagGlobally, type Flag } from '@/api/live/platform/flags';
import { Field, FormError, LoadError, Loading, field, primary, secondary } from '@/components/live/form';

const State: React.FC<{ label: string; active: boolean | null }> = ({ label, active }) => (
  <span className="text-[11px] text-text-secondary">{label}: <strong className={active === null ? 'text-text-muted' : active ? 'text-success' : 'text-danger'}>{active === null ? 'default' : active ? 'on' : 'off'}</strong></span>
);

const Row: React.FC<{ flag: Flag }> = ({ flag }) => {
  const qc = useQueryClient();
  const [tenant, setTenant] = useState('');
  const setGlobal = useMutation({
    mutationFn: (active: boolean | null) => setFlagGlobally(flag.name, active),
    onSuccess: () => { toast.success('Flag updated.'); void qc.invalidateQueries({ queryKey: ['admin', 'flags'] }); },
  });
  const setTenantFlag = useMutation({
    mutationFn: (active: boolean | null) => setFlagForTenant(tenant.trim(), flag.name, active),
    onSuccess: () => { toast.success('Tenant override saved.'); void qc.invalidateQueries({ queryKey: ['admin', 'flags'] }); },
  });
  const busy = setGlobal.isPending || setTenantFlag.isPending;

  return (
    <li className="flex flex-col gap-2 p-3 text-[12px]">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-semibold text-text-primary">{flag.name}</span>
        <span className="rounded bg-surface-neutral px-1.5 py-px font-mono text-[11px] text-text-secondary">{flag.description}</span>
        <State label="Default" active={flag.default} />
        <State label="Global" active={flag.global} />
        <span className="text-[11px] text-text-muted">{flag.overrides_count} override(s)</span>
        <div className="ml-auto flex gap-1">
          <button type="button" className={secondary} disabled={busy} onClick={() => setGlobal.mutate(true)}>On</button>
          <button type="button" className={secondary} disabled={busy} onClick={() => setGlobal.mutate(false)}>Off</button>
          <button type="button" className={secondary} disabled={busy} onClick={() => setGlobal.mutate(null)}>Default</button>
        </div>
      </div>
      <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); setTenantFlag.mutate(null); }}>
        <Field id={`tf-${flag.name}`} label="Tenant id for an override"><input id={`tf-${flag.name}`} className={`${field} w-72`} value={tenant} onChange={(e) => setTenant(e.target.value)} /></Field>
        <div className="flex gap-1">
          <button type="button" className={primary} disabled={busy || tenant.trim() === ''} onClick={() => setTenantFlag.mutate(true)}>Force on</button>
          <button type="button" className={secondary} disabled={busy || tenant.trim() === ''} onClick={() => setTenantFlag.mutate(false)}>Force off</button>
          <button type="button" className={secondary} disabled={busy || tenant.trim() === ''} onClick={() => setTenantFlag.mutate(null)}>Clear</button>
        </div>
      </form>
      <FormError error={setGlobal.error ?? setTenantFlag.error} />
    </li>
  );
};

export const LiveFlags: React.FC = () => {
  const q = useQuery({ queryKey: ['admin', 'flags'], queryFn: listFlags });
  return (
    <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 text-text-primary">
      <div className="flex items-center gap-2 border-b border-border pb-2">
        <h1 className="text-xl font-semibold">Feature flags</h1>
        <span className="text-[11px] text-text-muted">Global switch and per-tenant overrides</span>
      </div>
      {q.isPending && <Loading what="Loading flags" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && q.data.length === 0 && <p className="text-[13px] text-text-muted">No feature flags are defined.</p>}
      {q.data && q.data.length > 0 && <ul className="divide-y divide-border/60 rounded-lg border border-border bg-surface">{q.data.map((f) => <Row key={f.name} flag={f} />)}</ul>}
    </div>
  );
};
