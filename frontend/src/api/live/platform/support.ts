/** Live support tools (plan Task 15): global search, failed jobs, mail log, webhook deliveries and announcements. */
import { adminHttp } from '../../adminHttp';
import { createIntent, type Intent } from '../../idempotency';

export interface SearchResults {
  tenants: Array<{ id: string; name: string; slug: string; status: string; country: string | null }>;
  users: Array<{ id: string; name: string; email: string; tenant_ids: string[] }>;
  companies: Array<Record<string, unknown>>;
  invoices: Array<{ id: string; tenant_id: string; number: string | null; stripe_id: string | null; status: string; currency: string; total: number }>;
}
export interface FailedJob {
  id: string;
  job: string;
  queue: string;
  connection: string;
  failed_at: string;
  exception: string;
}
export interface MailRow {
  id: string;
  tenant_id: string | null;
  to: string;
  subject: string;
  notification: string | null;
  status: string;
  sent_at: string;
}
export interface WebhookRow {
  id: string;
  host: string;
  event_type: string;
  status: string;
  attempts: number;
  response_code: number | null;
  error: string | null;
  last_attempt_at: string | null;
  delivered_at: string | null;
  created_at: string;
}
export interface Announcement {
  id: string;
  title: string;
  body: string;
  kind: 'info' | 'warning' | 'maintenance';
  audience: { plans?: string[]; lifecycles?: string[]; countries?: string[] };
  starts_at: string;
  ends_at: string | null;
  created_by: string;
  created_at: string;
  updated_at: string;
}
export interface ImpersonationRow {
  id: string;
  tenant_id: string;
  admin_name: string | null;
  user_id: string;
  mode: string;
  reason: string;
  started_at: string | null;
  ends_at: string;
  ended_at: string | null;
  status: string;
  created_at: string;
}

const data = <T>(r: { data: T }): T => r.data;
const page = <T>(r: { data: T[]; meta: { next_cursor: string | null } }): { rows: T[]; nextCursor: string | null } => ({ rows: r.data, nextCursor: r.meta.next_cursor });

export const search = (q: string) => adminHttp.send<SearchResults>('GET', `/search?q=${encodeURIComponent(q)}`);

export const listFailedJobs = (cursor?: string) =>
  adminHttp.send<{ data: FailedJob[]; meta: { next_cursor: string | null } }>('GET', `/jobs/failed${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`).then(page);
export const retryJob = (uuid: string, intent: Intent = createIntent()) => adminHttp.send<unknown>('POST', `/jobs/failed/${encodeURIComponent(uuid)}/retry`, undefined, intent);
export const forgetJob = (uuid: string, intent: Intent = createIntent()) => adminHttp.send<void>('DELETE', `/jobs/failed/${encodeURIComponent(uuid)}`, undefined, intent);

export const listMailLog = (tenant?: string, cursor?: string) => {
  const q = new URLSearchParams();
  if (tenant) q.set('tenant', tenant);
  if (cursor) q.set('cursor', cursor);
  const s = q.toString();
  return adminHttp.send<{ data: MailRow[]; meta: { next_cursor: string | null } }>('GET', `/mail-log${s ? `?${s}` : ''}`).then(page);
};

export const listWebhookDeliveries = (tenantId: string, limit = 50) =>
  adminHttp.send<{ data: WebhookRow[] }>('GET', `/tenants/${encodeURIComponent(tenantId)}/webhook-deliveries?limit=${limit}`).then(data);

export const listImpersonations = (tenantId?: string) =>
  adminHttp.send<{ data: ImpersonationRow[] }>('GET', `/impersonations${tenantId ? `?tenant=${encodeURIComponent(tenantId)}` : ''}`).then(data);

export interface AnnouncementBody {
  title: string;
  body: string;
  kind: 'info' | 'warning' | 'maintenance';
  audience: { plans?: string[]; lifecycles?: string[]; countries?: string[] };
  starts_at: string;
  ends_at: string | null;
}
export const listAnnouncements = () => adminHttp.send<{ data: Announcement[] }>('GET', '/announcements').then(data);
export const createAnnouncement = (body: AnnouncementBody, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: Announcement }>('POST', '/announcements', body, intent).then(data);
export const updateAnnouncement = (id: string, body: AnnouncementBody, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: Announcement }>('PUT', `/announcements/${encodeURIComponent(id)}`, body, intent).then(data);
export const deleteAnnouncement = (id: string, intent: Intent = createIntent()) =>
  adminHttp.send<void>('DELETE', `/announcements/${encodeURIComponent(id)}`, undefined, intent);

