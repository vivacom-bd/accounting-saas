/**
 * Live admin tenants calls (plan Task 21): list, tenant page tabs, provisioning, lifecycle, support actions, subscription,
 * overrides, deletion request and impersonation. `createTenantsApi(http)` takes the client so tests can inject a fake fetch;
 * `tenantsApi` is bound to the real admin client.
 *
 * Admin controllers return untyped arrays, so most responses are `unknown` in schema-admin.d.ts. Each call below casts the
 * response to the `Api*` type read off the controller (adapters/platform/tenants.ts); that cast is the only one per call, and
 * the one `Loose` view of the client is the only place the typed path signatures are bypassed.
 */
import { adminHttp, type AdminHttpClient } from '../../adminHttp';
import { ApiError } from '../../errors';
import { createIntent, type Intent } from '../../idempotency';
import type { CursorPage } from '../../cursor';
import {
  toAuditEvent, toNote, toOverride, toSubscription, toTenantCompany, toTenantDetail, toTenantRow, toTenantUser,
  type ApiAuditEvent, type ApiNote, type ApiOverride, type ApiSubscription, type ApiTenant, type ApiTenantCompany, type ApiTenantDetail, type ApiTenantUser,
  type AuditEvent, type Override, type PlanOption, type Subscription, type TenantCompany, type TenantDetail, type TenantNote, type TenantRow, type TenantUser,
} from '../../adapters/platform/tenants';
import type { ImpersonationBody, ProvisionBody } from '../../adapters/platform/tenantForms';

export interface TenantListFilters {
  status?: string;
  plan?: string;
  country?: string;
  q?: string;
  sort?: 'created_at' | 'name';
}

/** Empty filters are left out; the country is the upper-case code the API validates. */
export function tenantListQuery(f: TenantListFilters): Record<string, string> {
  const out: Record<string, string> = {};
  if (f.status) out.status = f.status;
  if (f.plan) out.plan = f.plan;
  if (f.country?.trim()) out.country = f.country.trim().toUpperCase();
  if (f.q?.trim()) out.q = f.q.trim();
  if (f.sort) out.sort = f.sort;
  return out;
}

type Args = { path?: Record<string, string>; query?: Record<string, unknown>; body?: unknown; intent?: Intent };
interface Loose {
  get(path: string, a?: Args): Promise<unknown>;
  post(path: string, a?: Args): Promise<unknown>;
  put(path: string, a?: Args): Promise<unknown>;
  delete(path: string, a?: Args): Promise<unknown>;
}

export interface TenantsPage {
  rows: TenantRow[];
  nextCursor: string | null;
}
export interface OwnerInvitation {
  email: string;
  expiresAt: string;
}
export interface ProvisionResult {
  tenant: TenantRow;
  ownerInvitation: OwnerInvitation;
}
export interface ApprovalRef {
  id: string;
  status: string;
}
export interface ImpersonationStart {
  sessionId?: string;
  ticket?: string;
  url: string;
}
export type OverrideInput = { value: unknown; reason: string; expires_at?: string };

const T = '/tenants/{tenant}';

export function createTenantsApi(http: AdminHttpClient) {
  const c = http as unknown as Loose;
  const data = <X>(res: unknown): X => (res as { data: X }).data;
  const at = (tenant: string) => ({ path: { tenant } });
  const act = (tenant: string, body?: unknown, intent?: Intent): Args => ({ path: { tenant }, body, intent });

  return {
    async listTenants(filters: TenantListFilters, cursor?: string): Promise<TenantsPage> {
      const query: Record<string, unknown> = { ...tenantListQuery(filters) };
      if (cursor) query.cursor = cursor;
      const res = (await c.get('/tenants', { query })) as CursorPage<ApiTenant>;
      return { rows: res.data.map(toTenantRow), nextCursor: res.meta.next_cursor };
    },
    async getTenant(id: string): Promise<TenantDetail> {
      return toTenantDetail(data<ApiTenantDetail>(await c.get(T, at(id))));
    },
    async tenantUsers(id: string): Promise<TenantUser[]> {
      return data<ApiTenantUser[]>(await c.get(`${T}/users`, at(id))).map(toTenantUser);
    },
    async tenantCompanies(id: string): Promise<TenantCompany[]> {
      return data<ApiTenantCompany[]>(await c.get(`${T}/companies`, at(id))).map(toTenantCompany);
    },
    async tenantAudit(id: string, cursor?: string): Promise<{ events: AuditEvent[]; nextCursor: string | null }> {
      const res = (await c.get(`${T}/audit`, { path: { tenant: id }, query: cursor ? { cursor } : undefined })) as CursorPage<ApiAuditEvent>;
      return { events: res.data.map(toAuditEvent), nextCursor: res.meta.next_cursor };
    },
    async plans(): Promise<PlanOption[]> {
      return data<Array<{ code: string; name: string }>>(await c.get('/plans')).map((p) => ({ code: p.code, name: p.name }));
    },

    /** One Idempotency-Key per intent: pass the same `intent` to every retry of the same dialog submit. */
    async provisionTenant(body: ProvisionBody, intent: Intent = createIntent()): Promise<ProvisionResult> {
      const res = data<ApiTenant & { owner_invitation: { email: string; expires_at: string } }>(await c.post('/tenants', { body, intent }));
      return { tenant: toTenantRow(res), ownerInvitation: { email: res.owner_invitation.email, expiresAt: res.owner_invitation.expires_at } };
    },
    async resendOwnerInvitation(id: string): Promise<void> {
      await c.post(`${T}/resend-owner-invitation`, act(id));
    },
    async changeLifecycle(id: string, to: string, reason: string, intent?: Intent): Promise<TenantRow> {
      return toTenantRow(data<ApiTenant>(await c.post(`${T}/lifecycle`, act(id, { to, reason }, intent))));
    },
    /** Deletion is a two-person approval from compliance, not a lifecycle move: the answer is the pending approval. */
    async requestDeletion(id: string, reason: string, intent?: Intent): Promise<ApprovalRef> {
      return data<ApprovalRef>(await c.post(`${T}/deletion`, act(id, { reason }, intent)));
    },
    async addNote(id: string, body: string, intent?: Intent): Promise<TenantNote> {
      return toNote(data<ApiNote>(await c.post(`${T}/notes`, act(id, { body }, intent))));
    },
    async pinNote(id: string, note: string, pinned: boolean): Promise<TenantNote> {
      return toNote(data<ApiNote>(await c.post(`${T}/notes/{note}/pin`, { path: { tenant: id, note }, body: { pinned } })));
    },
    async resetTwoFactor(id: string, user: string, reason?: string): Promise<TenantUser> {
      return toTenantUser(data<ApiTenantUser>(await c.post(`${T}/users/{user}/reset-two-factor`, { path: { tenant: id, user }, body: { reason } })));
    },
    async disableUser(id: string, user: string, reason: string): Promise<TenantUser> {
      return toTenantUser(data<ApiTenantUser>(await c.post(`${T}/users/{user}/disable`, { path: { tenant: id, user }, body: { reason } })));
    },

    async billingStatus(): Promise<{ stripeConfigured: boolean }> {
      const res = (await c.get('/billing/status')) as { stripe_configured: boolean };
      return { stripeConfigured: res.stripe_configured };
    },
    async subscription(id: string): Promise<Subscription | null> {
      return toSubscription(data<ApiSubscription | null>(await c.get(`${T}/subscription`, at(id))));
    },
    /** `action` is the path segment: change-plan, cancel, resume, pause, extend-trial, convert-trial, apply-coupon, or '' to subscribe. */
    async subscriptionAction(id: string, action: string, body: Record<string, unknown>, intent?: Intent): Promise<Subscription | null> {
      const path = action ? `${T}/subscription/${action}` : `${T}/subscription`;
      return toSubscription(data<ApiSubscription | null>(await c.post(path, act(id, body, intent))));
    },
    async previewPlanChange(id: string, planCode: string, interval?: string): Promise<unknown> {
      return data<unknown>(await c.post(`${T}/subscription/preview`, act(id, { plan_code: planCode, interval })));
    },

    async overrides(id: string): Promise<Override[]> {
      return data<ApiOverride[]>(await c.get(`${T}/overrides`, at(id))).map(toOverride);
    },
    async putOverride(id: string, key: string, input: OverrideInput): Promise<void> {
      await c.put(`${T}/overrides/{key}`, { path: { tenant: id, key }, body: input });
    },
    async removeOverride(id: string, key: string): Promise<void> {
      await c.delete(`${T}/overrides/{key}`, { path: { tenant: id, key } });
    },

    /** Impersonation (plan Task 14) is on the build: the sessions log, start, write-mode request and end. */
    async impersonationAvailable(_id: string): Promise<boolean> {
      return true;
    },
    async listImpersonations(tenantId: string): Promise<unknown[]> {
      return data<unknown[]>(await c.get('/impersonations', { query: { tenant: tenantId } }));
    },
    async startImpersonation(id: string, body: ImpersonationBody, intent?: Intent): Promise<ImpersonationStart> {
      const res = (await c.post('/impersonations', { body: { tenant_id: id, ...body }, intent })) as Record<string, unknown>;
      const r = ((res.data as Record<string, unknown> | undefined) ?? res) as { session_id?: string; ticket?: string; url: string };
      return { sessionId: r.session_id, ticket: r.ticket, url: r.url };
    },
    async requestImpersonationWrite(id: string, body: { user_id: string; reason: string }, intent?: Intent): Promise<unknown> {
      return data<unknown>(await c.post('/impersonations/write-requests', { body: { tenant_id: id, ...body }, intent }));
    },
    async endImpersonation(sessionId: string, intent?: Intent): Promise<unknown> {
      return data<unknown>(await c.post('/impersonations/{id}/end', { path: { id: sessionId }, intent }));
    },
  };
}

export type TenantsApi = ReturnType<typeof createTenantsApi>;
export const tenantsApi: TenantsApi = createTenantsApi(adminHttp);
