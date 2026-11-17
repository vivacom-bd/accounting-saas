/**
 * Live staff and security (10_SaaS_Admin §3.11, plan Task 22): staff list, invitation, role, IP allowlist, deactivate and
 * two-factor reset, plus the two-person approvals queue. The audit log has its own page (LiveAuditLog). The mock's signing-PIN
 * step is not part of the API, so approving is a plain confirmation.
 */
import React, { useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { useAdminSession } from '@/data/adminSession';
import { approveApproval, deactivateStaff, inviteStaff, listApprovals, listStaff, rejectApproval, resetStaffTwoFactor, updateStaff } from '@/api/live/platform/staff';
import { createIntent } from '@/api/idempotency';
import { STAFF_ROLES, parseAllowlist, type ApiApproval, type ApiStaff } from '@/api/adapters/platform/staff';
import type { AdminRole } from '@/types/admin';
import { Field, LoadError, Loading, field, primary, secondary } from '@/components/live/form';
import { formatDateTime } from '@/lib/format';
import { ApprovalRow, StaffProblem } from './StaffViews';

const invalidateStaff = (qc: ReturnType<typeof useQueryClient>) => qc.invalidateQueries({ queryKey: ['admin', 'staff'] });

const AllowlistEditor: React.FC<{ member: ApiStaff; onDone: () => void }> = ({ member, onDone }) => {
  const qc = useQueryClient();
  const [text, setText] = useState(member.ip_allowlist.join('\n'));
  const [issues, setIssues] = useState<{ entry: string; message: string }[]>([]);
  const intent = useRef(createIntent());
  const save = useMutation({
    mutationFn: (entries: string[]) => updateStaff(member.id, { ip_allowlist: entries }, intent.current),
    onSuccess: () => {
      toast.success('Allowlist saved.');
      void invalidateStaff(qc);
      onDone();
    },
  });
  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const parsed = parseAllowlist(text);
    if (!parsed.ok) return setIssues(parsed.errors);
    setIssues([]);
    save.mutate(parsed.entries);
  };
  return (
    <form onSubmit={submit} className="flex flex-col gap-2 border-t border-border/60 bg-surface-neutral/40 p-3" aria-label={`IP allowlist for ${member.name}`}>
      <Field id={`allow-${member.id}`} label="Allowed addresses" hint="One IPv4, IPv6 or CIDR block per line. Empty means from anywhere.">
        <textarea id={`allow-${member.id}`} rows={4} className={`${field} h-auto py-2 font-mono`} value={text} onChange={(e) => setText(e.target.value)} />
      </Field>
      {issues.length > 0 && (
        <ul role="alert" className="text-xs text-danger">
          {issues.map((i) => <li key={i.entry}><span className="font-mono">{i.entry}</span>: {i.message}</li>)}
        </ul>
      )}
      <StaffProblem error={save.error} />
      <div className="flex gap-2">
        <button type="submit" className={primary} disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save allowlist'}</button>
        <button type="button" className={secondary} onClick={onDone}>Cancel</button>
      </div>
    </form>
  );
};

const InviteForm: React.FC<{ onDone: () => void }> = ({ onDone }) => {
  const qc = useQueryClient();
  const [email, setEmail] = useState('');
  const [name, setName] = useState('');
  const [role, setRole] = useState<AdminRole>('support');
  const intent = useRef(createIntent());
  const invite = useMutation({
    mutationFn: () => inviteStaff({ email: email.trim(), name: name.trim(), role }, intent.current),
    onSuccess: () => {
      toast.success('Invitation sent.');
      void invalidateStaff(qc);
      onDone();
    },
  });
  return (
    <form onSubmit={(e) => { e.preventDefault(); invite.mutate(); }} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4" aria-label="Invite staff">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Field id="invite-name" label="Name"><input id="invite-name" className={field} required value={name} onChange={(e) => setName(e.target.value)} /></Field>
        <Field id="invite-email" label="Email"><input id="invite-email" type="email" className={field} required value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
        <Field id="invite-role" label="Role">
          <select id="invite-role" className={field} value={role} onChange={(e) => setRole(e.target.value as AdminRole)}>{STAFF_ROLES.map((r) => <option key={r} value={r}>{r}</option>)}</select>
        </Field>
      </div>
      <StaffProblem error={invite.error} />
      <div className="flex gap-2">
        <button type="submit" className={primary} disabled={invite.isPending}>{invite.isPending ? 'Sending…' : 'Send invitation'}</button>
        <button type="button" className={secondary} onClick={onDone}>Cancel</button>
      </div>
    </form>
  );
};

const StaffTable: React.FC<{ staff: ApiStaff[]; meId: string }> = ({ staff, meId }) => {
  const qc = useQueryClient();
  const [editing, setEditing] = useState<string | null>(null);
  const role = useMutation({
    mutationFn: ({ id, role }: { id: string; role: AdminRole }) => updateStaff(id, { role }),
    onSuccess: () => { toast.success('Role changed.'); void invalidateStaff(qc); },
  });
  const deactivate = useMutation({
    mutationFn: (id: string) => deactivateStaff(id),
    onSuccess: () => { toast.success('Staff member deactivated.'); void invalidateStaff(qc); },
  });
  const reset = useMutation({
    mutationFn: (id: string) => resetStaffTwoFactor(id),
    onSuccess: () => { toast.success('Two-factor reset. They enrol again at next sign-in.'); void invalidateStaff(qc); },
  });
  const error = role.error ?? deactivate.error ?? reset.error;
  const busy = role.isPending || deactivate.isPending || reset.isPending;

  return (
    <div className="flex flex-col gap-2">
      <StaffProblem error={error} />
      <ul className="divide-y divide-border/60 rounded-lg border border-border bg-surface text-xs">
        {staff.map((m) => (
          <li key={m.id}>
            <div className="flex flex-wrap items-center gap-3 p-3">
              <div className="min-w-48">
                <div className="font-semibold text-text-primary">{m.name}{m.id === meId && <span className="ml-1 text-text-muted">(you)</span>}</div>
                <div className="font-mono text-[11px] text-text-muted">{m.email}</div>
              </div>
              <select
                aria-label={`Role of ${m.name}`} className={`${field} h-8 w-32 text-xs`} value={m.role} disabled={busy || !m.is_active}
                onChange={(e) => role.mutate({ id: m.id, role: e.target.value as AdminRole })}
              >
                {STAFF_ROLES.map((r) => <option key={r} value={r}>{r}</option>)}
              </select>
              <span className="text-text-secondary">
                {!m.is_active ? 'Deactivated' : m.invitation_pending ? 'Invitation pending' : m.two_factor_enabled ? 'Two-factor on' : 'Two-factor not set up'}
              </span>
              <span className="text-text-muted">{m.ip_allowlist.length === 0 ? 'Any address' : `${m.ip_allowlist.length} allowed`}</span>
              <span className="text-text-muted">{m.last_login_at ? `Last sign-in ${formatDateTime(m.last_login_at)}` : 'Never signed in'}</span>
              {m.is_active && (
                <div className="ml-auto flex gap-2">
                  <button type="button" className="text-accent hover:underline cursor-pointer" onClick={() => setEditing(editing === m.id ? null : m.id)}>IP allowlist</button>
                  <button
                    type="button" className="text-accent hover:underline cursor-pointer" disabled={busy}
                    onClick={() => { if (window.confirm(`Reset two-factor for ${m.name}? They must enrol again.`)) reset.mutate(m.id); }}
                  >
                    Reset 2FA
                  </button>
                  <button
                    type="button" className="text-danger hover:underline cursor-pointer" disabled={busy}
                    onClick={() => { if (window.confirm(`Deactivate ${m.name}? They are signed out and cannot sign in.`)) deactivate.mutate(m.id); }}
                  >
                    Deactivate
                  </button>
                </div>
              )}
            </div>
            {editing === m.id && <AllowlistEditor member={m} onDone={() => setEditing(null)} />}
          </li>
        ))}
      </ul>
    </div>
  );
};

const ApprovalsQueue: React.FC<{ meId: string; can: string[]; names: Record<string, string> }> = ({ meId, can, names }) => {
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['admin', 'approvals', 'pending'], queryFn: () => listApprovals('pending') });
  const [rejecting, setRejecting] = useState<ApiApproval | null>(null);
  const [reason, setReason] = useState('');
  const done = () => { void qc.invalidateQueries({ queryKey: ['admin', 'approvals'] }); };
  const approve = useMutation({ mutationFn: (a: ApiApproval) => approveApproval(a.id), onSuccess: () => { toast.success('Approved.'); done(); } });
  const reject = useMutation({
    mutationFn: (a: ApiApproval) => rejectApproval(a.id, reason.trim()),
    onSuccess: () => { toast.success('Rejected.'); setRejecting(null); setReason(''); done(); },
  });
  return (
    <section className="flex flex-col gap-2" aria-label="Approvals">
      <h2 className="text-[15px] font-semibold text-text-primary">Approvals waiting</h2>
      <p className="text-xs text-text-muted">Refunds, write-mode support access and tenant deletion need a second staff member. You cannot decide your own request.</p>
      {q.isPending && <Loading what="Loading approvals" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && q.data.length === 0 && <p className="text-xs text-text-muted">Nothing is waiting.</p>}
      {q.data && q.data.length > 0 && (
        <ul className="divide-y divide-border/60 rounded-lg border border-border bg-surface">
          {q.data.map((a) => (
            <ApprovalRow
              key={a.id} approval={a} meId={meId} can={can} names={names} busy={approve.isPending || reject.isPending}
              onApprove={(x) => approve.mutate(x)} onReject={(x) => { setRejecting(x); setReason(''); }}
            />
          ))}
        </ul>
      )}
      <StaffProblem error={approve.error ?? reject.error} />
      {rejecting && (
        <form onSubmit={(e) => { e.preventDefault(); if (reason.trim() !== '') reject.mutate(rejecting); }} className="flex flex-col gap-2 rounded-lg border border-border bg-surface p-3" aria-label="Reject request">
          <Field id="reject-reason" label="Why is this rejected?"><input id="reject-reason" className={field} value={reason} onChange={(e) => setReason(e.target.value)} required /></Field>
          <div className="flex gap-2">
            <button type="submit" className={primary} disabled={reject.isPending || reason.trim() === ''}>Reject request</button>
            <button type="button" className={secondary} onClick={() => setRejecting(null)}>Cancel</button>
          </div>
        </form>
      )}
    </section>
  );
};

export const LiveStaffSecurity: React.FC = () => {
  const session = useAdminSession();
  const me = session.data;
  const q = useQuery({ queryKey: ['admin', 'staff'], queryFn: listStaff });
  const [inviting, setInviting] = useState(false);
  const names = useMemo(() => Object.fromEntries((q.data ?? []).map((s) => [s.id, s.name])), [q.data]);

  return (
    <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 text-text-primary">
      <div className="flex items-center gap-2 border-b border-border pb-2">
        <h1 className="text-xl font-semibold">Staff and security</h1>
        {!inviting && <button type="button" className={`ml-auto ${primary}`} onClick={() => setInviting(true)}>Invite staff</button>}
      </div>
      {inviting && <InviteForm onDone={() => setInviting(false)} />}
      {q.isPending && <Loading what="Loading staff" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && me && <StaffTable staff={q.data} meId={me.id} />}
      {me && <ApprovalsQueue meId={me.id} can={me.can} names={names} />}
    </div>
  );
};
