/**
 * Live support (10 §3.8, plan Task 15): global search, failed jobs (retry/forget), the mail log, announcements, and the
 * impersonation log. It shows only what the API returns; payloads and secrets are never surfaced by the backend.
 */
import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  createAnnouncement, deleteAnnouncement, forgetJob, listAnnouncements, listFailedJobs, listImpersonations, listMailLog, retryJob, search,
  type AnnouncementBody,
} from '@/api/live/platform/support';
import { formatDateTime } from '@/lib/format';
import { Field, FormError, LoadError, Loading, field, primary, secondary } from '@/components/live/form';

type Tab = 'search' | 'jobs' | 'mail' | 'announcements' | 'impersonations';

const SearchTab: React.FC = () => {
  const [q, setQ] = useState('');
  const [applied, setApplied] = useState('');
  const res = useQuery({ queryKey: ['admin', 'support', 'search', applied], queryFn: () => search(applied), enabled: applied.length >= 2 });
  return (
    <div className="flex flex-col gap-3">
      <form className="flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); setApplied(q.trim()); }}>
        <Field id="se-q" label="Search (tenant, email, company, invoice)"><input id="se-q" className={`${field} w-96`} value={q} onChange={(e) => setQ(e.target.value)} /></Field>
        <button type="submit" className={primary} disabled={q.trim().length < 2}>Search</button>
      </form>
      {res.isFetching && <Loading what="Searching" />}
      {res.isError && <LoadError error={res.error} onRetry={() => void res.refetch()} />}
      {res.data && (
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
          <Group title={`Tenants (${res.data.tenants.length})`}>{res.data.tenants.map((t) => <li key={t.id} className="py-1"><span className="font-medium">{t.name}</span> <span className="font-mono text-text-muted">{t.slug}</span> · {t.status}</li>)}</Group>
          <Group title={`Users (${res.data.users.length})`}>{res.data.users.map((u) => <li key={u.id} className="py-1"><span className="font-medium">{u.name}</span> <span className="font-mono text-text-muted">{u.email}</span></li>)}</Group>
          <Group title={`Companies (${res.data.companies.length})`}>{res.data.companies.map((c, i) => <li key={i} className="py-1 font-mono">{String(c.name ?? '')} <span className="text-text-muted">{String(c.tenant_name ?? '')}</span></li>)}</Group>
          <Group title={`Invoices (${res.data.invoices.length})`}>{res.data.invoices.map((i) => <li key={i.id} className="py-1"><span className="font-mono">{i.number ?? i.stripe_id}</span> · {i.status} · {i.currency} {i.total}</li>)}</Group>
        </div>
      )}
    </div>
  );
};

const Group: React.FC<{ title: string; children: React.ReactNode }> = ({ title, children }) => (
  <section className="rounded-lg border border-border bg-surface p-3">
    <h3 className="mb-1 text-[13px] font-semibold">{title}</h3>
    <ul className="list-none text-[12px]">{children}</ul>
  </section>
);

const JobsTab: React.FC = () => {
  const qc = useQueryClient();
  const [cursor, setCursor] = useState<string | undefined>();
  const q = useQuery({ queryKey: ['admin', 'support', 'jobs', cursor ?? null], queryFn: () => listFailedJobs(cursor) });
  const refresh = () => { setCursor(undefined); void qc.invalidateQueries({ queryKey: ['admin', 'support', 'jobs'] }); };
  const retry = useMutation({ mutationFn: (id: string) => retryJob(id), onSuccess: () => { toast.success('Job queued again.'); refresh(); } });
  const forget = useMutation({ mutationFn: (id: string) => forgetJob(id), onSuccess: () => { toast.success('Job forgotten.'); refresh(); } });
  return (
    <div className="flex flex-col gap-2">
      {q.isPending && <Loading what="Loading failed jobs" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      <FormError error={retry.error ?? forget.error} />
      {q.data && q.data.rows.length === 0 && <p className="text-[13px] text-text-muted">No failed jobs.</p>}
      {q.data && q.data.rows.length > 0 && (
        <ul className="divide-y divide-border/60 rounded-lg border border-border bg-surface">
          {q.data.rows.map((j) => (
            <li key={j.id} className="flex flex-wrap items-start gap-3 p-3 text-[12px]">
              <div className="min-w-0 flex-1">
                <div className="font-mono font-semibold text-danger">{j.job}</div>
                <div className="text-text-muted">queue {j.queue} · {formatDateTime(j.failed_at)}</div>
                <div className="mt-1 break-all rounded border border-border/60 bg-surface-neutral/60 p-1.5 font-mono text-[11px] text-text-secondary">{j.exception}</div>
              </div>
              <div className="flex gap-1">
                <button type="button" className={secondary} disabled={retry.isPending} onClick={() => retry.mutate(j.id)}>Retry</button>
                <button type="button" className={secondary} disabled={forget.isPending} onClick={() => forget.mutate(j.id)}>Forget</button>
              </div>
            </li>
          ))}
        </ul>
      )}
      {q.data?.nextCursor && <div className="flex justify-center"><button type="button" className={secondary} onClick={() => setCursor(q.data?.nextCursor ?? undefined)}>Load more</button></div>}
    </div>
  );
};

const MailTab: React.FC = () => {
  const q = useQuery({ queryKey: ['admin', 'support', 'mail'], queryFn: () => listMailLog() });
  return (
    <div className="flex flex-col gap-2">
      {q.isPending && <Loading what="Loading the mail log" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && q.data.rows.length === 0 && <p className="text-[13px] text-text-muted">No mail sent yet.</p>}
      {q.data && q.data.rows.length > 0 && (
        <table className="w-full text-left text-[12px]">
          <thead className="text-text-muted"><tr><th className="py-1">To</th><th className="py-1">Subject</th><th className="py-1">Notification</th><th className="py-1">Sent</th></tr></thead>
          <tbody className="divide-y divide-border/60">
            {q.data.rows.map((m) => (
              <tr key={m.id}><td className="py-1 font-mono">{m.to}</td><td className="py-1">{m.subject}</td><td className="py-1 font-mono text-text-muted">{m.notification ?? '—'}</td><td className="py-1 font-mono text-text-muted">{formatDateTime(m.sent_at)}</td></tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
};

const emptyAnnouncement = (): AnnouncementBody => ({
  title: '', body: '', kind: 'info', audience: {},
  starts_at: new Date().toISOString(), ends_at: null,
});

const AnnouncementsTab: React.FC = () => {
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['admin', 'support', 'announcements'], queryFn: listAnnouncements });
  const [form, setForm] = useState<AnnouncementBody>(emptyAnnouncement);
  const refresh = () => qc.invalidateQueries({ queryKey: ['admin', 'support', 'announcements'] });
  const create = useMutation({ mutationFn: () => createAnnouncement(form), onSuccess: () => { toast.success('Announcement created.'); setForm(emptyAnnouncement()); void refresh(); } });
  const remove = useMutation({ mutationFn: (id: string) => deleteAnnouncement(id), onSuccess: () => { toast.success('Announcement deleted.'); void refresh(); } });

  return (
    <div className="flex flex-col gap-3">
      <form className="grid grid-cols-2 items-end gap-2 rounded-lg border border-border bg-surface p-3 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); create.mutate(); }}>
        <Field id="an-title" label="Title"><input id="an-title" className={field} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></Field>
        <Field id="an-kind" label="Kind"><select id="an-kind" className={field} value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value as AnnouncementBody['kind'] })}>
          {['info', 'warning', 'maintenance'].map((k) => <option key={k} value={k}>{k}</option>)}
        </select></Field>
        <Field id="an-starts" label="Starts"><input id="an-starts" type="datetime-local" className={field} value={form.starts_at.slice(0, 16)} onChange={(e) => setForm({ ...form, starts_at: new Date(e.target.value).toISOString() })} /></Field>
        <Field id="an-ends" label="Ends (optional)"><input id="an-ends" type="datetime-local" className={field} value={form.ends_at ? form.ends_at.slice(0, 16) : ''} onChange={(e) => setForm({ ...form, ends_at: e.target.value ? new Date(e.target.value).toISOString() : null })} /></Field>
        <div className="col-span-2 sm:col-span-4"><Field id="an-body" label="Body"><textarea id="an-body" rows={2} className={`${field} h-auto py-1.5`} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} /></Field></div>
        <div className="col-span-2 sm:col-span-4 flex items-center gap-2">
          <button type="submit" className={primary} disabled={create.isPending || form.title.trim() === '' || form.body.trim() === ''}>Post announcement</button>
          <span className="text-[11px] text-text-muted">An empty audience targets everyone.</span>
        </div>
        <div className="col-span-2 sm:col-span-4"><FormError error={create.error} /></div>
      </form>

      {q.isPending && <Loading what="Loading announcements" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && q.data.length === 0 && <p className="text-[13px] text-text-muted">No announcements.</p>}
      {q.data && q.data.length > 0 && (
        <ul className="divide-y divide-border/60 rounded-lg border border-border bg-surface">
          {q.data.map((a) => (
            <li key={a.id} className="flex items-start gap-3 p-3 text-[12px]">
              <div className="min-w-0 flex-1">
                <div className="font-semibold">{a.title} <span className="rounded bg-surface-neutral px-1.5 py-px font-mono text-[11px] text-text-secondary">{a.kind}</span></div>
                <p className="text-text-secondary">{a.body}</p>
                <div className="mt-1 font-mono text-[11px] text-text-muted">{formatDateTime(a.starts_at)}{a.ends_at ? ` → ${formatDateTime(a.ends_at)}` : ' (open ended)'}</div>
              </div>
              <button type="button" className="text-danger hover:underline cursor-pointer" disabled={remove.isPending} onClick={() => remove.mutate(a.id)}>Delete</button>
            </li>
          ))}
        </ul>
      )}
      <FormError error={remove.error} />
    </div>
  );
};

const ImpersonationsTab: React.FC = () => {
  const q = useQuery({ queryKey: ['admin', 'support', 'impersonations'], queryFn: () => listImpersonations() });
  return (
    <div className="flex flex-col gap-2">
      {q.isPending && <Loading what="Loading the impersonation log" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && q.data.length === 0 && <p className="text-[13px] text-text-muted">No impersonation sessions recorded.</p>}
      {q.data && q.data.length > 0 && (
        <table className="w-full text-left text-[12px]">
          <thead className="text-text-muted"><tr><th className="py-1">Staff</th><th className="py-1">Tenant</th><th className="py-1">Mode</th><th className="py-1">Started</th><th className="py-1">Ends</th><th className="py-1">Status</th></tr></thead>
          <tbody className="divide-y divide-border/60">
            {q.data.map((r) => (
              <tr key={r.id}><td className="py-1">{r.admin_name ?? '—'}</td><td className="py-1 font-mono text-text-muted">{r.tenant_id}</td><td className="py-1">{r.mode}</td>
                <td className="py-1 font-mono text-text-muted">{r.started_at ? formatDateTime(r.started_at) : '—'}</td>
                <td className="py-1 font-mono text-text-muted">{formatDateTime(r.ends_at)}</td><td className="py-1">{r.status}</td></tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
};

export const LiveSupport: React.FC = () => {
  const [tab, setTab] = useState<Tab>('search');
  const tabs: Array<{ id: Tab; label: string }> = [
    { id: 'search', label: 'Search' }, { id: 'jobs', label: 'Failed jobs' }, { id: 'mail', label: 'Mail log' },
    { id: 'announcements', label: 'Announcements' }, { id: 'impersonations', label: 'Impersonation log' },
  ];
  return (
    <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 text-text-primary">
      <div className="flex items-center gap-2 border-b border-border pb-2"><h1 className="text-xl font-semibold">Support and communications</h1></div>
      <div role="tablist" className="flex flex-wrap gap-1 border-b border-border text-xs">
        {tabs.map((t) => (
          <button key={t.id} type="button" role="tab" aria-selected={tab === t.id} onClick={() => setTab(t.id)}
            className={`px-3 py-2 border-b-2 -mb-px cursor-pointer ${tab === t.id ? 'border-accent text-text-primary font-medium' : 'border-transparent text-text-secondary hover:text-text-primary'}`}>
            {t.label}
          </button>
        ))}
      </div>
      {tab === 'search' && <SearchTab />}
      {tab === 'jobs' && <JobsTab />}
      {tab === 'mail' && <MailTab />}
      {tab === 'announcements' && <AnnouncementsTab />}
      {tab === 'impersonations' && <ImpersonationsTab />}
    </div>
  );
};
