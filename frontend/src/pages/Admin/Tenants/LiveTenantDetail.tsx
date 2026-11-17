/**
 * Live tenant page (10 §3.2): Overview (limits vs usage, lifecycle timeline), Subscription, Users, Companies, Overrides,
 * Notes, Audit. A tab or action the signed-in staff member's abilities do not cover is not rendered. Health score,
 * data residency, DPA and tickets are not in the API and are not shown.
 */
import React, { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { describeError } from '@/api/messages';
import { tenantsApi } from '@/api/live/platform/tenants';
import { useAdminSession } from '@/data/adminSession';
import type { LimitUsage, TenantDetail, TenantUser } from '@/api/adapters/platform/tenants';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { tenantActionsFor, visibleTenantTabs, type TenantTabId } from './tenantAccess';
import { Field, inputCls, btnPrimary, btnGhost, btnDanger, ErrorLine, LifecyclePill, dateOrDash } from './tenantUi';
import { LifecycleActionDialog } from './LifecycleActionDialog';
import { SubscriptionPanel } from './SubscriptionPanel';
import { ImpersonateDialog } from './ImpersonateDialog';

export const TenantTabsNav: React.FC<{ can: string[]; active: TenantTabId; onSelect: (id: TenantTabId) => void }> = ({ can, active, onSelect }) => (
  <div role="tablist" className="flex items-center gap-1 border-b border-border text-xs overflow-x-auto">
    {visibleTenantTabs(can).map((t) => (
      <button
        key={t.id}
        role="tab"
        type="button"
        aria-selected={active === t.id}
        onClick={() => onSelect(t.id)}
        className={cn('px-3 py-2 border-b-2 -mb-px cursor-pointer', active === t.id ? 'border-accent text-text-primary font-medium' : 'border-transparent text-text-secondary hover:text-text-primary')}
      >
        {t.label}
      </button>
    ))}
  </div>
);

const METRIC_NAMES: Record<string, string> = { companies: 'Companies', users: 'Users' };

export const LimitsUsage: React.FC<{ limits: LimitUsage[] }> = ({ limits }) => (
  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
    {limits.map((l) => {
      const pct = l.limit && l.limit > 0 ? Math.min(100, Math.round((l.used / l.limit) * 100)) : 0;
      return (
        <div key={l.metric} className="border border-border rounded-md p-3 bg-surface">
          <div className="flex items-baseline justify-between text-[13px]">
            <span className="font-medium">{METRIC_NAMES[l.metric] ?? l.metric}</span>
            <span className="font-mono">
              {l.used} / {l.limit === null ? 'Unlimited' : l.limit}
            </span>
          </div>
          {l.limit !== null && (
            <div className="mt-2 h-1.5 rounded bg-surface-neutral overflow-hidden">
              <div className={cn('h-full', pct >= 100 ? 'bg-danger' : pct >= 80 ? 'bg-warning' : 'bg-accent')} style={{ width: `${pct}%` }} />
            </div>
          )}
        </div>
      );
    })}
  </div>
);

/** One reason, one confirm: used for disabling a person and resetting their two-factor. */
const ReasonDialog: React.FC<{ title: string; description: string; required: boolean; confirmLabel: string; open: boolean; busy: boolean; error: string | null; onConfirm: (reason: string) => void; onClose: () => void }> = ({
  title, description, required, confirmLabel, open, busy, error, onConfirm, onClose,
}) => {
  const [reason, setReason] = useState('');
  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <Field label={required ? 'Reason' : 'Reason (optional)'} htmlFor="rd-reason">
          <textarea id="rd-reason" rows={3} className={`${inputCls} h-auto py-1.5`} value={reason} onChange={(e) => setReason(e.target.value)} />
        </Field>
        <ErrorLine message={error} />
        <div className="flex justify-end gap-2">
          <button type="button" className={btnGhost} onClick={onClose}>Cancel</button>
          <button type="button" className={btnDanger} disabled={busy || (required && !reason.trim())} onClick={() => onConfirm(reason.trim())}>{busy ? 'Saving…' : confirmLabel}</button>
        </div>
      </DialogContent>
    </Dialog>
  );
};

const Overview: React.FC<{ t: TenantDetail }> = ({ t }) => (
  <div className="flex flex-col gap-4">
    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-[13px]">
      <div><div className="text-[11px] text-text-muted">Owner</div><div>{t.ownerName ?? '—'}</div><div className="text-text-muted text-[12px]">{t.ownerEmail}</div></div>
      <div><div className="text-[11px] text-text-muted">Plan</div><div>{t.planName ?? '—'}</div></div>
      <div><div className="text-[11px] text-text-muted">Country</div><div>{t.country ?? '—'}</div></div>
      <div><div className="text-[11px] text-text-muted">Created</div><div>{dateOrDash(t.createdAt)}</div></div>
      <div><div className="text-[11px] text-text-muted">Trial ends</div><div>{dateOrDash(t.trialEndsAt)}</div></div>
      <div><div className="text-[11px] text-text-muted">Last activity</div><div>{dateOrDash(t.lastActivityAt)}</div></div>
    </div>
    <section>
      <h3 className="text-[13px] font-semibold mb-2">Limits and usage</h3>
      {t.limits.length ? <LimitsUsage limits={t.limits} /> : <p className="text-[13px] text-text-muted">No plan limits apply.</p>}
    </section>
    <section>
      <h3 className="text-[13px] font-semibold mb-2">Lifecycle timeline</h3>
      {t.timeline.length === 0 ? (
        <p className="text-[13px] text-text-muted">No changes yet.</p>
      ) : (
        <ol className="flex flex-col gap-2">
          {t.timeline.map((e) => (
            <li key={e.id} className="text-[13px] flex flex-wrap items-center gap-2">
              <span className="text-text-muted w-24">{dateOrDash(e.occurredAt)}</span>
              <LifecyclePill status={e.from} /> <span aria-hidden>→</span> <LifecyclePill status={e.to} />
              <span className="text-text-secondary">{e.reason}</span>
            </li>
          ))}
        </ol>
      )}
    </section>
  </div>
);

const UsersTab: React.FC<{ tenantId: string; canSupport: boolean }> = ({ tenantId, canSupport }) => {
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['admin', 'tenants', tenantId, 'users'], queryFn: () => tenantsApi.tenantUsers(tenantId) });
  const [target, setTarget] = useState<{ user: TenantUser; kind: 'disable' | 'reset' } | null>(null);
  const run = useMutation({
    mutationFn: ({ reason }: { reason: string }) =>
      target?.kind === 'disable' ? tenantsApi.disableUser(tenantId, target.user.id, reason) : tenantsApi.resetTwoFactor(tenantId, target!.user.id, reason || undefined),
    onSuccess: () => {
      toast.success(target?.kind === 'disable' ? 'Access disabled.' : 'Two-factor reset.');
      setTarget(null);
      void qc.invalidateQueries({ queryKey: ['admin', 'tenants', tenantId, 'users'] });
    },
  });
  if (q.isPending) return <p className="text-[13px] text-text-muted">Loading…</p>;
  if (q.error) return <ErrorLine message={describeError(q.error)} />;
  return (
    <>
      <div className="overflow-x-auto border border-border rounded-md bg-surface">
        <table className="w-full text-[12px]">
          <thead className="bg-surface-neutral text-text-secondary text-left"><tr>
            <th className="px-3 py-2 font-medium">Person</th><th className="px-3 py-2 font-medium">Role</th><th className="px-3 py-2 font-medium">Status</th>
            <th className="px-3 py-2 font-medium">Two-factor</th><th className="px-3 py-2 font-medium">Last sign-in</th>{canSupport && <th className="px-3 py-2" />}
          </tr></thead>
          <tbody>
            {q.data.map((u) => (
              <tr key={u.id} className="border-t border-border">
                <td className="px-3 py-2"><div className="font-medium">{u.name}</div><div className="text-text-muted">{u.email}</div></td>
                <td className="px-3 py-2">{u.orgRole}</td><td className="px-3 py-2">{u.status}</td>
                <td className="px-3 py-2">{u.twoFactor ? 'On' : 'Off'}</td><td className="px-3 py-2">{dateOrDash(u.lastLoginAt)}</td>
                {canSupport && (
                  <td className="px-3 py-2 text-right whitespace-nowrap">
                    <button type="button" className={btnGhost} onClick={() => setTarget({ user: u, kind: 'reset' })} disabled={!u.twoFactor}>Reset two-factor</button>{' '}
                    <button type="button" className={btnDanger} onClick={() => setTarget({ user: u, kind: 'disable' })} disabled={u.status === 'disabled'}>Disable</button>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {target && (
        <ReasonDialog
          open
          title={target.kind === 'disable' ? `Disable ${target.user.name}` : `Reset two-factor for ${target.user.name}`}
          description={target.kind === 'disable' ? 'They lose access to this organisation until re-enabled.' : 'They must enrol a new authenticator at next sign-in.'}
          required={target.kind === 'disable'}
          confirmLabel={target.kind === 'disable' ? 'Disable access' : 'Reset two-factor'}
          busy={run.isPending}
          error={run.error ? describeError(run.error) : null}
          onConfirm={(reason) => run.mutate({ reason })}
          onClose={() => { setTarget(null); run.reset(); }}
        />
      )}
    </>
  );
};

const CompaniesTab: React.FC<{ tenantId: string }> = ({ tenantId }) => {
  const q = useQuery({ queryKey: ['admin', 'tenants', tenantId, 'companies'], queryFn: () => tenantsApi.tenantCompanies(tenantId) });
  if (q.isPending) return <p className="text-[13px] text-text-muted">Loading…</p>;
  if (q.error) return <ErrorLine message={describeError(q.error)} />;
  if (q.data.length === 0) return <p className="text-[13px] text-text-muted">No companies yet; the owner has not finished onboarding.</p>;
  return (
    <div className="overflow-x-auto border border-border rounded-md bg-surface">
      <table className="w-full text-[12px]">
        <thead className="bg-surface-neutral text-text-secondary text-left"><tr>
          <th className="px-3 py-2 font-medium">Company</th><th className="px-3 py-2 font-medium">Base currency</th><th className="px-3 py-2 font-medium">Fiscal year starts</th>
          <th className="px-3 py-2 font-medium text-right">Open periods</th><th className="px-3 py-2 font-medium text-right">Closed periods</th><th className="px-3 py-2 font-medium text-right">Posted journals</th>
        </tr></thead>
        <tbody>
          {q.data.map((c) => (
            <tr key={c.id} className="border-t border-border">
              <td className="px-3 py-2 font-medium">{c.name}</td><td className="px-3 py-2">{c.baseCurrency}</td><td className="px-3 py-2">{dateOrDash(c.fiscalYearStart)}</td>
              <td className="px-3 py-2 text-right font-mono">{c.openPeriods}</td><td className="px-3 py-2 text-right font-mono">{c.closedPeriods}</td><td className="px-3 py-2 text-right font-mono">{c.postedJournals}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

/** "true"/"false" -> boolean, "null" -> null (unlimited), whole numbers -> integers; anything else is refused. */
export function parseOverrideValue(text: string): { ok: true; value: boolean | number | null } | { ok: false } {
  const s = text.trim().toLowerCase();
  if (s === 'true') return { ok: true, value: true };
  if (s === 'false') return { ok: true, value: false };
  if (s === 'null' || s === 'unlimited') return { ok: true, value: null };
  return /^\d+$/.test(s) ? { ok: true, value: Number(s) } : { ok: false };
}

const OverridesTab: React.FC<{ tenantId: string; canEdit: boolean }> = ({ tenantId, canEdit }) => {
  const qc = useQueryClient();
  const key = ['admin', 'tenants', tenantId, 'overrides'];
  const q = useQuery({ queryKey: key, queryFn: () => tenantsApi.overrides(tenantId) });
  const [f, setF] = useState({ key: '', value: '', reason: '' });
  const [local, setLocal] = useState<string | null>(null);
  const put = useMutation({
    mutationFn: () => {
      const v = parseOverrideValue(f.value);
      if (!v.ok) throw new Error('bad value');
      return tenantsApi.putOverride(tenantId, f.key.trim(), { value: v.value, reason: f.reason.trim() });
    },
    onSuccess: () => { setF({ key: '', value: '', reason: '' }); void qc.invalidateQueries({ queryKey: key }); },
  });
  const del = useMutation({ mutationFn: (k: string) => tenantsApi.removeOverride(tenantId, k), onSuccess: () => qc.invalidateQueries({ queryKey: key }) });
  if (q.isPending) return <p className="text-[13px] text-text-muted">Loading…</p>;
  if (q.error) return <ErrorLine message={describeError(q.error)} />;
  const err = put.error ?? del.error;
  return (
    <div className="flex flex-col gap-3">
      {q.data.length === 0 ? <p className="text-[13px] text-text-muted">No overrides; the plan's limits and features apply.</p> : (
        <ul className="flex flex-col gap-1 text-[13px]">
          {q.data.map((o) => (
            <li key={o.key} className="flex items-center justify-between border border-border rounded-md px-3 py-2 bg-surface">
              <span><span className="font-mono">{o.key}</span> = <span className="font-mono">{JSON.stringify(o.value)}</span>{!o.active && ' (expired)'} <span className="text-text-muted">{o.reason}</span></span>
              {canEdit && <button type="button" className={btnGhost} onClick={() => del.mutate(o.key)} disabled={del.isPending}>Remove</button>}
            </li>
          ))}
        </ul>
      )}
      {canEdit && (
        <form
          className="grid grid-cols-1 sm:grid-cols-4 gap-2 items-end"
          onSubmit={(e) => {
            e.preventDefault();
            if (!parseOverrideValue(f.value).ok) { setLocal('Use true, false, null (unlimited) or a whole number.'); return; }
            setLocal(null);
            put.mutate();
          }}
        >
          <Field label="Limit or feature" htmlFor="ov-key"><input id="ov-key" className={inputCls} value={f.key} onChange={(e) => setF({ ...f, key: e.target.value })} /></Field>
          <Field label="Value" htmlFor="ov-val"><input id="ov-val" className={inputCls} value={f.value} onChange={(e) => setF({ ...f, value: e.target.value })} /></Field>
          <Field label="Reason" htmlFor="ov-reason"><input id="ov-reason" className={inputCls} value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} /></Field>
          <button type="submit" className={btnPrimary} disabled={put.isPending || !f.key.trim() || f.reason.trim().length < 3}>Set override</button>
        </form>
      )}
      <ErrorLine message={local ?? (err ? describeError(err) : null)} />
    </div>
  );
};

const NotesTab: React.FC<{ tenant: TenantDetail; canAdd: boolean }> = ({ tenant, canAdd }) => {
  const qc = useQueryClient();
  const [body, setBody] = useState('');
  const refresh = () => qc.invalidateQueries({ queryKey: ['admin', 'tenants', tenant.id] });
  const add = useMutation({ mutationFn: () => tenantsApi.addNote(tenant.id, body.trim()), onSuccess: () => { setBody(''); void refresh(); } });
  const pin = useMutation({ mutationFn: (n: { id: string; pinned: boolean }) => tenantsApi.pinNote(tenant.id, n.id, n.pinned), onSuccess: () => refresh() });
  return (
    <div className="flex flex-col gap-3">
      {canAdd && (
        <form className="flex flex-col gap-2" onSubmit={(e) => { e.preventDefault(); if (body.trim()) add.mutate(); }}>
          <textarea aria-label="New note" rows={3} className={`${inputCls} h-auto py-1.5`} placeholder="Add an internal note" value={body} onChange={(e) => setBody(e.target.value)} />
          <div className="flex justify-end"><button type="submit" className={btnPrimary} disabled={add.isPending || !body.trim()}>Add note</button></div>
        </form>
      )}
      {tenant.notes.length === 0 && <p className="text-[13px] text-text-muted">No notes.</p>}
      <ul className="flex flex-col gap-2">
        {tenant.notes.map((n) => (
          <li key={n.id} className="border border-border rounded-md p-3 bg-surface text-[13px]">
            <div className="flex items-center justify-between text-[11px] text-text-muted">
              <span>{n.authorName ?? 'Staff'} · {dateOrDash(n.createdAt)}{n.pinned && ' · Pinned'}</span>
              {canAdd && <button type="button" className="underline cursor-pointer" onClick={() => pin.mutate({ id: n.id, pinned: !n.pinned })}>{n.pinned ? 'Unpin' : 'Pin'}</button>}
            </div>
            <p className="mt-1 whitespace-pre-wrap">{n.body}</p>
          </li>
        ))}
      </ul>
      <ErrorLine message={add.error ? describeError(add.error) : pin.error ? describeError(pin.error) : null} />
    </div>
  );
};

const AuditTab: React.FC<{ tenantId: string }> = ({ tenantId }) => {
  const [cursors, setCursors] = useState<string[]>([]);
  const last = cursors[cursors.length - 1];
  const q = useQuery({ queryKey: ['admin', 'tenants', tenantId, 'audit', last ?? 'first'], queryFn: () => tenantsApi.tenantAudit(tenantId, last) });
  if (q.isPending) return <p className="text-[13px] text-text-muted">Loading…</p>;
  if (q.error) return <ErrorLine message={describeError(q.error)} />;
  return (
    <div className="flex flex-col gap-2">
      {q.data.events.length === 0 && <p className="text-[13px] text-text-muted">No staff actions recorded for this tenant.</p>}
      <ul className="flex flex-col gap-1 text-[12px]">
        {q.data.events.map((e) => (
          <li key={e.id} className="border border-border rounded-md px-3 py-2 bg-surface flex flex-wrap gap-x-3">
            <span className="text-text-muted">{dateOrDash(e.occurredAt)}</span><span className="font-mono">{e.action}</span>{e.reason && <span className="text-text-secondary">{e.reason}</span>}
          </li>
        ))}
      </ul>
      <div className="flex gap-2">
        {cursors.length > 0 && <button type="button" className={btnGhost} onClick={() => setCursors((c) => c.slice(0, -1))}>Newer</button>}
        {q.data.nextCursor && <button type="button" className={btnGhost} onClick={() => setCursors((c) => [...c, q.data.nextCursor as string])}>Older</button>}
      </div>
    </div>
  );
};

export const LiveTenantDetail: React.FC = () => {
  const { id = '' } = useParams<{ id: string }>();
  const session = useAdminSession().data;
  const can = session?.can ?? [];
  const actions = tenantActionsFor(can);
  const tabs = visibleTenantTabs(can);
  const [tab, setTab] = useState<TenantTabId>('overview');
  const [statusOpen, setStatusOpen] = useState(false);
  const [impersonateOpen, setImpersonateOpen] = useState(false);

  const q = useQuery({ queryKey: ['admin', 'tenants', id, 'detail'], queryFn: () => tenantsApi.getTenant(id), enabled: id !== '' });
  const impersonation = useQuery({ queryKey: ['admin', 'impersonation-available', id], queryFn: () => tenantsApi.impersonationAvailable(id), enabled: actions.impersonate && id !== '', staleTime: Infinity });
  const resend = useMutation({ mutationFn: () => tenantsApi.resendOwnerInvitation(id), onSuccess: () => toast.success('Invitation sent again.') });

  if (q.isPending) return <p className="p-4 text-[13px] text-text-muted">Loading…</p>;
  if (q.error) return <div className="p-4"><ErrorLine message={describeError(q.error)} /><Link to="/admin/tenants" className="text-[13px] underline">Back to tenants</Link></div>;
  const t = q.data;
  const activeTab = tabs.some((x) => x.id === tab) ? tab : (tabs[0]?.id ?? 'overview');

  return (
    <div className="flex-1 flex flex-col p-4 space-y-3 max-w-7xl mx-auto w-full text-text-primary">
      <div className="text-[12px] text-text-muted"><Link to="/admin/tenants" className="hover:underline">Tenants</Link> / {t.name}</div>
      <div className="flex flex-wrap items-center justify-between gap-2 pb-2 border-b border-border">
        <div className="flex items-center gap-2">
          <h1 className="text-xl font-semibold">{t.name}</h1>
          <LifecyclePill status={t.status} />
          <span className="font-mono text-[12px] text-text-muted">{t.slug}</span>
        </div>
        <div className="flex flex-wrap gap-2">
          {actions.resendInvitation && <button type="button" className={btnGhost} onClick={() => resend.mutate()} disabled={resend.isPending}>Resend owner invitation</button>}
          {actions.impersonate && impersonation.data === true && <button type="button" className={btnGhost} onClick={() => setImpersonateOpen(true)}>Impersonate</button>}
          {(actions.lifecycle || actions.deletion) && <button type="button" className={btnPrimary} onClick={() => setStatusOpen(true)}>Change status</button>}
        </div>
      </div>
      <ErrorLine message={resend.error ? describeError(resend.error) : null} />
      {tabs.length === 0 ? (
        <p className="text-[13px] text-text-muted">You do not have access to tenant details.</p>
      ) : (
        <>
          <TenantTabsNav can={can} active={activeTab} onSelect={setTab} />
          <div className="pt-2">
            {activeTab === 'overview' && <Overview t={t} />}
            {activeTab === 'subscription' && <SubscriptionPanel tenantId={id} canManage={actions.manageBilling} />}
            {activeTab === 'users' && <UsersTab tenantId={id} canSupport={actions.userSupport} />}
            {activeTab === 'companies' && <CompaniesTab tenantId={id} />}
            {activeTab === 'overrides' && <OverridesTab tenantId={id} canEdit={actions.overrides} />}
            {activeTab === 'notes' && <NotesTab tenant={t} canAdd={actions.notes} />}
            {activeTab === 'audit' && <AuditTab tenantId={id} />}
          </div>
        </>
      )}
      {(actions.lifecycle || actions.deletion) && (
        <LifecycleActionDialog tenantId={id} tenantName={t.name} from={t.status} open={statusOpen} onOpenChange={setStatusOpen} canRequestDeletion={actions.deletion} />
      )}
      {actions.impersonate && impersonation.data === true && (
        <ImpersonateDialog tenantId={id} tenantName={t.name} open={impersonateOpen} onOpenChange={setImpersonateOpen} canWrite={can.includes('impersonate.write.request')} isOwner={session?.role === 'owner'} />
      )}
    </div>
  );
};
