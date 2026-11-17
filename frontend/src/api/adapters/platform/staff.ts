/**
 * Staff, approvals and the admin audit log for the admin console (plan Task 22). Pure: no React, no network.
 * Responses of these endpoints are typed `unknown` in schema-admin.d.ts, so the shapes are written out here and
 * cast once at the live call boundary (api/live/platform/staff.ts).
 */
import { ApiError } from '@/api/errors';
import { describeError } from '@/api/messages';
import { formatMoney } from '@/lib/format';
import type { AdminRole } from '@/types/admin';

export const STAFF_ROLES: AdminRole[] = ['owner', 'finance', 'sales', 'support', 'engineer', 'readonly'];

export interface ApiStaff {
  id: string;
  name: string;
  email: string;
  role: AdminRole;
  is_active: boolean;
  two_factor_enabled: boolean;
  ip_allowlist: string[];
  last_login_at: string | null;
  invitation_pending: boolean;
  created_at: string | null;
}

export type ApprovalKind = 'refund' | 'impersonate_write' | 'plan_migration' | 'tenant_delete' | 'legal_hold_release';
export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'executed' | 'expired';
export interface ApiApproval {
  id: string;
  kind: ApprovalKind;
  tenant_id: string | null;
  subject: Record<string, unknown>;
  reason: string;
  requested_by: string;
  approved_by: string | null;
  status: ApprovalStatus;
  rejection_reason: string | null;
  expires_at: string;
  decided_at: string | null;
  executed_at: string | null;
  created_at: string | null;
}

export interface ApiAuditEvent {
  id: number;
  admin_id: string | null;
  action: string;
  tenant_id: string | null;
  subject_type: string;
  subject_id: string | null;
  before: unknown;
  after: unknown;
  reason: string | null;
  ip: string | null;
  user_agent: string | null;
  request_id: string | null;
  occurred_at: string;
}
export interface ApiAuditPage {
  data: ApiAuditEvent[];
  meta: { next_cursor: string | null; prev_cursor: string | null; limit: number };
}
export interface ApiVerifyReport {
  intact: boolean;
  events: number;
  broken_id: number | null;
}

/* IP allowlist */

const MAX_ALLOWLIST = 50;

function isIPv4(s: string): boolean {
  const parts = s.split('.');
  return parts.length === 4 && parts.every((p) => /^\d{1,3}$/.test(p) && Number(p) <= 255);
}

function isIPv6(s: string): boolean {
  if (!/^[0-9a-fA-F:]+$/.test(s) || s.includes(':::')) return false;
  const halves = s.split('::');
  if (halves.length > 2) return false;
  const groups = (h: string) => (h === '' ? [] : h.split(':'));
  const all = halves.flatMap(groups);
  if (!all.every((g) => /^[0-9a-fA-F]{1,4}$/.test(g))) return false;
  return halves.length === 2 ? all.length < 8 : all.length === 8;
}

/** null when the entry is an IPv4/IPv6 address or a CIDR block, otherwise what is wrong with it. */
export function validateAllowEntry(entry: string): string | null {
  const text = entry.trim();
  if (text === '') return 'Empty entry.';
  const [addr, prefix, ...rest] = text.split('/');
  if (rest.length > 0) return 'Use one address or one address/prefix.';
  const v4 = isIPv4(addr);
  const v6 = !v4 && isIPv6(addr);
  if (!v4 && !v6) return 'Not an IPv4 or IPv6 address.';
  if (prefix !== undefined) {
    if (!/^\d{1,3}$/.test(prefix)) return 'The prefix must be a number.';
    if (Number(prefix) > (v4 ? 32 : 128)) return `The prefix cannot be more than ${v4 ? 32 : 128}.`;
  }
  return null;
}

export type AllowlistParse = { ok: true; entries: string[] } | { ok: false; errors: { entry: string; message: string }[] };

/** One entry per line or separated by commas. An empty list is valid: it means "from anywhere". */
export function parseAllowlist(text: string): AllowlistParse {
  const entries = text.split(/[\n,]+/).map((e) => e.trim()).filter((e) => e !== '');
  const errors = entries.flatMap((entry) => {
    const message = validateAllowEntry(entry);
    return message ? [{ entry, message }] : [];
  });
  if (entries.length > MAX_ALLOWLIST) errors.push({ entry: `${entries.length} entries`, message: `At most ${MAX_ALLOWLIST} entries.` });
  return errors.length > 0 ? { ok: false, errors } : { ok: true, entries };
}

/* approvals */

/** Approve and reject show only on a pending request made by someone else, for a staff member who may decide. */
export function canDecideApproval(a: Pick<ApiApproval, 'requested_by' | 'status'>, meId: string, can: string[]): boolean {
  return a.status === 'pending' && a.requested_by !== meId && can.includes('approvals.decide');
}

const KIND_TITLES: Record<ApprovalKind, string> = {
  refund: 'Refund',
  impersonate_write: 'Write-mode support access',
  plan_migration: 'Plan migration',
  tenant_delete: 'Tenant deletion',
  legal_hold_release: 'Legal hold release',
};

export function approvalHeadline(a: Pick<ApiApproval, 'kind' | 'subject'>): string {
  const title = KIND_TITLES[a.kind] ?? a.kind;
  const { amount, currency } = a.subject as { amount?: unknown; currency?: unknown };
  if (a.kind === 'refund' && typeof amount === 'number' && Number.isInteger(amount) && typeof currency === 'string') return `${title} ${formatMoney(amount, currency)}`;
  return title;
}

/* audit */

export interface AuditFilters {
  admin: string;
  tenant: string;
  action: string;
  from: string;
  to: string;
}
export const emptyAuditFilters = (): AuditFilters => ({ admin: '', tenant: '', action: '', from: '', to: '' });

export function auditQuery(f: AuditFilters, cursor?: string): Record<string, string> {
  const q: Record<string, string> = {};
  for (const k of ['admin', 'tenant', 'action', 'from', 'to'] as const) if (f[k].trim() !== '') q[k] = f[k].trim();
  if (cursor) q.cursor = cursor;
  return q;
}

export function verifyOutcome(r: ApiVerifyReport): { tone: 'ok' | 'broken'; text: string } {
  return r.intact
    ? { tone: 'ok', text: `Chain intact: ${r.events} events checked.` }
    : { tone: 'broken', text: `Chain broken at event ${r.broken_id}.` };
}

/* errors */

export function staffProblemMessage(e: unknown): string {
  if (e instanceof ApiError) {
    if (e.code === 'last_staff_owner') return 'There must always be at least one active owner. Make someone else an owner first.';
    if (e.code === 'admin_ip_self_lockout') return 'That allowlist would lock you out from your current address. Add your address or leave the list empty.';
  }
  return describeError(e);
}
