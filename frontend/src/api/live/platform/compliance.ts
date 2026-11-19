/** Live compliance (plan Task 18): GDPR request queue, retention schedule, legal hold and deletion requests. */
import { adminHttp } from '../../adminHttp';
import { createIntent, type Intent } from '../../idempotency';

export interface RetentionRow {
  tenant_id: string;
  name: string;
  lifecycle: string;
  status: string;
  scheduled_for: string;
  days_left: number;
  legal_hold: boolean;
  archive_until: string | null;
}
export interface GdprRow {
  id: string;
  tenant_id: string;
  kind: 'export' | 'erasure' | 'rectification' | 'access';
  subject_email_masked: string;
  status: 'open' | 'in_progress' | 'done' | 'rejected';
  due_at: string;
  overdue: boolean;
  completed_at: string | null;
  notes: string | null;
  created_at: string;
}
export interface ApprovalRef {
  id: string;
  kind: string;
  status: string;
}

const data = <T>(r: { data: T }): T => r.data;

export const getRetention = () => adminHttp.send<{ data: RetentionRow[] }>('GET', '/compliance/retention').then(data);

export function gdprQuery(f: { status?: string; kind?: string; overdue?: boolean }): string {
  const q = new URLSearchParams();
  if (f.status) q.set('status', f.status);
  if (f.kind) q.set('kind', f.kind);
  if (f.overdue) q.set('overdue', '1');
  const s = q.toString();
  return s ? `?${s}` : '';
}
export const listGdpr = (f: { status?: string; kind?: string; overdue?: boolean }) =>
  adminHttp.send<{ data: GdprRow[]; meta: { fulfilment: string; fulfilment_note: string } }>('GET', `/compliance/gdpr-requests${gdprQuery(f)}`);
export const createGdpr = (body: { kind: string; tenant_id: string; subject_email: string; note?: string }, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: GdprRow }>('POST', '/compliance/gdpr-requests', body, intent).then(data);
export const updateGdpr = (id: string, body: { status: string; note?: string }, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: GdprRow }>('PATCH', `/compliance/gdpr-requests/${encodeURIComponent(id)}`, body, intent).then(data);

export const requestDeletion = (tenantId: string, reason: string, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: ApprovalRef }>('POST', `/tenants/${encodeURIComponent(tenantId)}/deletion`, { reason }, intent).then(data);
export const setLegalHold = (tenantId: string, reason: string, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: { tenant_id: string; legal_hold: boolean } }>('POST', `/tenants/${encodeURIComponent(tenantId)}/legal-hold`, { reason }, intent).then(data);
export const requestHoldRelease = (tenantId: string, reason: string, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: ApprovalRef }>('DELETE', `/tenants/${encodeURIComponent(tenantId)}/legal-hold`, { reason }, intent).then(data);
export const setArchiveUntil = (tenantId: string, archiveUntil: string | null, reason: string, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: { tenant_id: string; archive_until: string | null } }>('PUT', `/tenants/${encodeURIComponent(tenantId)}/archive-until`, { archive_until: archiveUntil, reason }, intent).then(data);
