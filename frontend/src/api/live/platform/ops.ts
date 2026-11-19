/** Live platform operations (plan Task 17): health, maintenance mode and tenant rate-limit overrides. */
import { adminHttp } from '../../adminHttp';
import { createIntent, type Intent } from '../../idempotency';

export interface Health {
  database: string;
  redis: string;
  queue: { status: string; pending: number | null; failed: number | null; queues: Record<string, number> };
  outbox_backlog: { status: string; pending: number | null; oldest_at: string | null };
  scheduler_last_beat: { status: string; at: string | null };
  audit_seal_last_run: { status: string; at: string | null };
  mail_sent_24h: number | null;
}
export interface Maintenance {
  enabled: boolean;
  message: string | null;
  ends_at: string | null;
}
export interface RateLimit {
  per_minute: number;
  reason: string;
  expires_at: string | null;
}

const data = <T>(r: { data: T }): T => r.data;

export const getHealth = () => adminHttp.send<{ data: Health }>('GET', '/ops/health').then(data);
export const getMaintenance = () => adminHttp.send<{ data: Maintenance }>('GET', '/ops/maintenance').then(data);
export const setMaintenance = (body: { enabled: boolean; message?: string | null; ends_at?: string | null }, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: Maintenance }>('PUT', '/ops/maintenance', body, intent).then(data);

export const getRateLimit = (tenantId: string) =>
  adminHttp.send<{ data: RateLimit | null }>('GET', `/tenants/${encodeURIComponent(tenantId)}/rate-limit`).then(data);
export const setRateLimit = (tenantId: string, body: { per_minute: number; reason: string; expires_at?: string | null }, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: RateLimit | null }>('PUT', `/tenants/${encodeURIComponent(tenantId)}/rate-limit`, body, intent).then(data);
export const clearRateLimit = (tenantId: string, intent: Intent = createIntent()) =>
  adminHttp.send<void>('DELETE', `/tenants/${encodeURIComponent(tenantId)}/rate-limit`, undefined, intent);
