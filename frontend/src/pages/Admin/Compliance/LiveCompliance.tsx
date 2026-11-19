/**
 * Live compliance (10 §3.12, plan Task 18): the GDPR request queue (tracking only; fulfilment is manual), the retention
 * schedule of scheduled deletions, and tenant-scoped actions (deletion request, legal hold, archive-until). None of these
 * purges anything: deletion runs in Phase 4.7.
 */
import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  createGdpr, getRetention, listGdpr, requestDeletion, requestHoldRelease, setArchiveUntil, setLegalHold, updateGdpr, type GdprRow,
} from '@/api/live/platform/compliance';
import { Field, FormError, LoadError, Loading, field, primary, secondary } from '@/components/live/form';
import { formatDate } from '@/lib/format';

const KIND: Record<string, string> = { export: 'Export', erasure: 'Erasure', rectification: 'Rectification', access: 'Access' };

const GdprQueue: React.FC = () => {
  const qc = useQueryClient();
  const [filters, setFilters] = useState<{ status?: string; kind?: string; overdue?: boolean }>({});
  const [form, setForm] = useState({ kind: 'export', tenant_id: '', subject_email: '', note: '' });
  const q = useQuery({ queryKey: ['admin', 'compliance', 'gdpr', filters], queryFn: () => listGdpr(filters) });
  const refresh = () => qc.invalidateQueries({ queryKey: ['admin', 'compliance', 'gdpr'] });
  const create = useMutation({
    mutationFn: () => createGdpr({ kind: form.kind, tenant_id: form.tenant_id.trim(), subject_email: form.subject_email.trim(), note: form.note.trim() || undefined }),
    onSuccess: () => { toast.success('Request opened.'); setForm({ kind: 'export', tenant_id: '', subject_email: '', note: '' }); void refresh(); },
  });
  const update = useMutation({
    mutationFn: (r: { row: GdprRow; status: string }) => updateGdpr(r.row.id, { status: r.status }),
    onSuccess: () => { toast.success('Request updated.'); void refresh(); },
  });

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-end gap-2">
        <Field id="gd-status" label="Status"><select id="gd-status" className={field} value={filters.status ?? ''} onChange={(e) => setFilters({ ...filters, status: e.target.value || undefined })}>
          <option value="">All</option>{['open', 'in_progress', 'done', 'rejected'].map((s) => <option key={s} value={s}>{s}</option>)}
        </select></Field>
        <Field id="gd-kind" label="Kind"><select id="gd-kind" className={field} value={filters.kind ?? ''} onChange={(e) => setFilters({ ...filters, kind: e.target.value || undefined })}>
          <option value="">All</option>{Object.keys(KIND).map((k) => <option key={k} value={k}>{KIND[k]}</option>)}
        </select></Field>
        <label className="flex items-center gap-2 pb-2 text-[13px]"><input type="checkbox" checked={filters.overdue ?? false} onChange={(e) => setFilters({ ...filters, overdue: e.target.checked || undefined })} />Overdue only</label>
      </div>

      <form className="grid grid-cols-2 items-end gap-2 rounded-lg border border-border bg-surface p-3 sm:grid-cols-5" onSubmit={(e) => { e.preventDefault(); create.mutate(); }}>
        <Field id="gd-new-kind" label="Kind"><select id="gd-new-kind" className={field} value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
          {Object.keys(KIND).map((k) => <option key={k} value={k}>{KIND[k]}</option>)}
        </select></Field>
        <Field id="gd-tenant" label="Tenant id"><input id="gd-tenant" className={field} value={form.tenant_id} onChange={(e) => setForm({ ...form, tenant_id: e.target.value })} /></Field>
        <Field id="gd-email" label="Subject email"><input id="gd-email" type="email" className={field} value={form.subject_email} onChange={(e) => setForm({ ...form, subject_email: e.target.value })} /></Field>
        <Field id="gd-note" label="Note"><input id="gd-note" className={field} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} /></Field>
        <button type="submit" className={primary} disabled={create.isPending || !form.tenant_id || !form.subject_email}>Open request</button>
        <div className="col-span-2 sm:col-span-5"><FormError error={create.error} /></div>
      </form>

      {q.isPending && <Loading what="Loading requests" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && (
        <>
          <p className="text-[11px] text-text-muted">{q.data.meta.fulfilment_note}</p>
          <div className="overflow-x-auto rounded-lg border border-border bg-surface">
            <table className="w-full text-left text-[12px]">
              <thead className="bg-surface-neutral text-text-muted"><tr>
                <th className="px-3 py-2">Kind</th><th className="px-3 py-2">Tenant</th><th className="px-3 py-2">Subject</th>
                <th className="px-3 py-2">Status</th><th className="px-3 py-2">Due</th><th className="px-3 py-2 text-right">Action</th>
              </tr></thead>
              <tbody className="divide-y divide-border/60">
                {q.data.data.length === 0 && <tr><td colSpan={6} className="px-3 py-6 text-center text-text-muted">No requests match.</td></tr>}
                {q.data.data.map((r) => (
                  <tr key={r.id} className={r.overdue ? 'bg-danger-soft/30' : ''}>
                    <td className="px-3 py-1.5">{KIND[r.kind] ?? r.kind}</td><td className="px-3 py-1.5 font-mono text-text-muted">{r.tenant_id}</td>
                    <td className="px-3 py-1.5 font-mono">{r.subject_email_masked}</td><td className="px-3 py-1.5">{r.status}{r.overdue ? ' (overdue)' : ''}</td>
                    <td className="px-3 py-1.5 font-mono">{formatDate(r.due_at.slice(0, 10))}</td>
                    <td className="px-3 py-1.5 text-right">
                      {['open', 'in_progress'].includes(r.status) && (
                        <span className="flex justify-end gap-1">
                          <button type="button" className="text-accent hover:underline cursor-pointer" disabled={update.isPending} onClick={() => update.mutate({ row: r, status: 'in_progress' })}>Start</button>
                          <button type="button" className="text-accent hover:underline cursor-pointer" disabled={update.isPending} onClick={() => update.mutate({ row: r, status: 'done' })}>Done</button>
                          <button type="button" className="text-danger hover:underline cursor-pointer" disabled={update.isPending} onClick={() => update.mutate({ row: r, status: 'rejected' })}>Reject</button>
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <FormError error={update.error} />
        </>
      )}
    </div>
  );
};

const TenantActions: React.FC = () => {
  const qc = useQueryClient();
  const [t, setT] = useState({ tenant: '', reason: '', archive: '' });
  const refresh = () => qc.invalidateQueries({ queryKey: ['admin', 'compliance', 'retention'] });
  const del = useMutation({ mutationFn: () => requestDeletion(t.tenant.trim(), t.reason.trim()), onSuccess: () => { toast.success('Deletion request opened — needs an owner to approve.'); void refresh(); } });
  const hold = useMutation({ mutationFn: () => setLegalHold(t.tenant.trim(), t.reason.trim()), onSuccess: () => { toast.success('Legal hold set.'); void refresh(); } });
  const release = useMutation({ mutationFn: () => requestHoldRelease(t.tenant.trim(), t.reason.trim()), onSuccess: () => { toast.success('Release requested — needs an owner to approve.'); void refresh(); } });
  const archive = useMutation({ mutationFn: () => setArchiveUntil(t.tenant.trim(), t.archive || null, t.reason.trim()), onSuccess: () => { toast.success('Archive date saved.'); void refresh(); } });
  const busy = del.isPending || hold.isPending || release.isPending || archive.isPending;

  return (
    <section className="flex flex-col gap-2">
      <h2 className="text-[15px] font-semibold">Tenant compliance actions</h2>
      <form className="grid grid-cols-2 items-end gap-2 sm:grid-cols-4" onSubmit={(e) => e.preventDefault()}>
        <Field id="ta-tenant" label="Tenant id"><input id="ta-tenant" className={field} value={t.tenant} onChange={(e) => setT({ ...t, tenant: e.target.value })} /></Field>
        <Field id="ta-reason" label="Reason"><input id="ta-reason" className={field} value={t.reason} onChange={(e) => setT({ ...t, reason: e.target.value })} /></Field>
        <Field id="ta-archive" label="Archive until (optional)"><input id="ta-archive" type="date" className={field} value={t.archive} onChange={(e) => setT({ ...t, archive: e.target.value })} /></Field>
        <div className="flex flex-wrap gap-1">
          <button type="button" className={secondary} disabled={busy || !t.tenant || !t.reason} onClick={() => del.mutate()}>Request deletion</button>
          <button type="button" className={secondary} disabled={busy || !t.tenant || !t.reason} onClick={() => hold.mutate()}>Set legal hold</button>
          <button type="button" className={secondary} disabled={busy || !t.tenant || !t.reason} onClick={() => release.mutate()}>Release hold</button>
          <button type="button" className={secondary} disabled={busy || !t.tenant || !t.reason} onClick={() => archive.mutate()}>Save archive</button>
        </div>
      </form>
      <FormError error={del.error ?? hold.error ?? release.error ?? archive.error} />
    </section>
  );
};

const Retention: React.FC = () => {
  const q = useQuery({ queryKey: ['admin', 'compliance', 'retention'], queryFn: getRetention });
  return (
    <section className="flex flex-col gap-2">
      <h2 className="text-[15px] font-semibold">Retention schedule</h2>
      {q.isPending && <Loading what="Loading the schedule" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && q.data.length === 0 && <p className="text-[13px] text-text-muted">No scheduled deletions.</p>}
      {q.data && q.data.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="w-full text-left text-[12px]">
            <thead className="bg-surface-neutral text-text-muted"><tr>
              <th className="px-3 py-2">Tenant</th><th className="px-3 py-2">Lifecycle</th><th className="px-3 py-2">Deletion status</th>
              <th className="px-3 py-2">Scheduled for</th><th className="px-3 py-2 text-right">Days left</th><th className="px-3 py-2">Legal hold</th><th className="px-3 py-2">Archive until</th>
            </tr></thead>
            <tbody className="divide-y divide-border/60">
              {q.data.map((r) => (
                <tr key={r.tenant_id}>
                  <td className="px-3 py-1.5"><div className="font-medium">{r.name}</div><div className="font-mono text-[11px] text-text-muted">{r.tenant_id}</div></td>
                  <td className="px-3 py-1.5">{r.lifecycle}</td><td className="px-3 py-1.5">{r.status}</td>
                  <td className="px-3 py-1.5 font-mono">{r.scheduled_for}</td>
                  <td className={`px-3 py-1.5 text-right font-mono ${r.days_left < 0 ? 'text-danger' : ''}`}>{r.days_left}</td>
                  <td className="px-3 py-1.5">{r.legal_hold ? 'Yes' : '—'}</td><td className="px-3 py-1.5 font-mono">{r.archive_until ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
};

export const LiveCompliance: React.FC = () => {
  const [tab, setTab] = useState<'gdpr' | 'retention'>('gdpr');
  return (
    <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 text-text-primary">
      <div className="flex items-center gap-2 border-b border-border pb-2"><h1 className="text-xl font-semibold">Compliance and GDPR</h1></div>
      <div role="tablist" className="flex gap-1 border-b border-border text-xs">
        {(['gdpr', 'retention'] as const).map((id) => (
          <button key={id} type="button" role="tab" aria-selected={tab === id} onClick={() => setTab(id)}
            className={`px-3 py-2 border-b-2 -mb-px cursor-pointer ${tab === id ? 'border-accent text-text-primary font-medium' : 'border-transparent text-text-secondary hover:text-text-primary'}`}>
            {id === 'gdpr' ? 'GDPR requests' : 'Retention'}
          </button>
        ))}
      </div>
      {tab === 'gdpr' && <><GdprQueue /><TenantActions /></>}
      {tab === 'retention' && <Retention />}
    </div>
  );
};
