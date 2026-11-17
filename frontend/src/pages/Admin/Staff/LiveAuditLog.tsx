/** Live admin audit log (10_SaaS_Admin §3.11, plan Task 22): filters, cursor paging and the hash-chain check. */
import React, { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { listAudit, verifyAuditChain } from '@/api/live/platform/staff';
import { auditQuery, emptyAuditFilters, type AuditFilters } from '@/api/adapters/platform/staff';
import { Field, FormError, LoadError, Loading, field, primary, secondary } from '@/components/live/form';
import { formatDateTime } from '@/lib/format';
import { VerifyResult } from './StaffViews';

export const LiveAuditLog: React.FC = () => {
  const [draft, setDraft] = useState<AuditFilters>(emptyAuditFilters);
  const [applied, setApplied] = useState<AuditFilters>(emptyAuditFilters);
  const [cursor, setCursor] = useState<string | undefined>();
  const q = useQuery({ queryKey: ['admin', 'audit', applied, cursor ?? null], queryFn: () => listAudit(auditQuery(applied, cursor)) });
  const verify = useMutation({ mutationFn: () => verifyAuditChain() });
  const set = (k: keyof AuditFilters) => (e: React.ChangeEvent<HTMLInputElement>) => setDraft({ ...draft, [k]: e.target.value });

  return (
    <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 text-text-primary">
      <div className="flex items-center gap-2 border-b border-border pb-2">
        <h1 className="text-xl font-semibold">Audit log</h1>
        <button type="button" className={`ml-auto ${secondary}`} disabled={verify.isPending} onClick={() => verify.mutate()}>{verify.isPending ? 'Checking…' : 'Verify chain'}</button>
      </div>
      {verify.data && <VerifyResult report={verify.data} />}
      <FormError error={verify.error} />

      <form onSubmit={(e) => { e.preventDefault(); setCursor(undefined); setApplied(draft); }} className="grid grid-cols-2 items-end gap-3 sm:grid-cols-6" aria-label="Filters">
        <Field id="audit-admin" label="Staff id"><input id="audit-admin" className={field} value={draft.admin} onChange={set('admin')} /></Field>
        <Field id="audit-tenant" label="Tenant id"><input id="audit-tenant" className={field} value={draft.tenant} onChange={set('tenant')} /></Field>
        <Field id="audit-action" label="Action"><input id="audit-action" className={field} placeholder="tenant.suspended" value={draft.action} onChange={set('action')} /></Field>
        <Field id="audit-from" label="From"><input id="audit-from" type="date" className={field} value={draft.from} onChange={set('from')} /></Field>
        <Field id="audit-to" label="To"><input id="audit-to" type="date" className={field} value={draft.to} onChange={set('to')} /></Field>
        <button type="submit" className={primary}>Filter</button>
      </form>

      {q.isPending && <Loading what="Loading events" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && q.data.data.length === 0 && <p className="text-[13px] text-text-muted">No events match.</p>}
      {q.data && q.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="w-full border-collapse text-left text-xs">
            <thead>
              <tr className="h-8 border-b border-border bg-surface-neutral/60 text-[11px] font-semibold uppercase text-text-muted">
                <th className="px-3">When</th><th className="px-3">Action</th><th className="px-3">Staff</th><th className="px-3">Tenant</th><th className="px-3">Subject</th><th className="px-3">Reason</th><th className="px-3">IP</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border/60">
              {q.data.data.map((e) => (
                <tr key={e.id} className="h-9">
                  <td className="px-3 whitespace-nowrap text-text-secondary">{formatDateTime(e.occurred_at)}</td>
                  <td className="px-3 font-mono font-semibold">{e.action}</td>
                  <td className="px-3 font-mono text-text-muted">{e.admin_id ?? 'system'}</td>
                  <td className="px-3 font-mono text-text-muted">{e.tenant_id ?? ''}</td>
                  <td className="px-3 font-mono text-text-muted">{e.subject_type}{e.subject_id ? ` ${e.subject_id}` : ''}</td>
                  <td className="px-3 text-text-secondary">{e.reason ?? ''}</td>
                  <td className="px-3 font-mono text-text-muted">{e.ip ?? ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {q.data && (cursor || q.data.meta.next_cursor) && (
        <div className="flex gap-2">
          {cursor && <button type="button" className={secondary} onClick={() => setCursor(undefined)}>First page</button>}
          {q.data.meta.next_cursor && <button type="button" className={secondary} onClick={() => setCursor(q.data.meta.next_cursor ?? undefined)}>Older events</button>}
        </div>
      )}
    </div>
  );
};
