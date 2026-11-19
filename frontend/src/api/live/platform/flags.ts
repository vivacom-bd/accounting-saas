/** Live feature flags (plan Task 17): list, the global switch and per-tenant overrides. */
import { adminHttp } from '../../adminHttp';
import { createIntent, type Intent } from '../../idempotency';

export interface Flag {
  name: string;
  description: string;
  default: boolean;
  global: boolean | null;
  overrides_count: number;
}

const data = <T>(r: { data: T }): T => r.data;

export const listFlags = () => adminHttp.send<{ data: Flag[] }>('GET', '/flags').then(data);

/** `active` null clears the switch (back to the default / no override). */
export const setFlagGlobally = (name: string, active: boolean | null, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: { name: string; active: boolean | null } }>('PUT', `/flags/${encodeURIComponent(name)}`, { active }, intent).then(data);

export const setFlagForTenant = (tenantId: string, name: string, active: boolean | null, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: { tenant_id: string; name: string; active: boolean | null } }>(
    'PUT', `/tenants/${encodeURIComponent(tenantId)}/flags/${encodeURIComponent(name)}`, { active }, intent,
  ).then(data);
