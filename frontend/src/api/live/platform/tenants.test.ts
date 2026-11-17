import { describe, it, expect, vi } from 'vitest';
import { createAdminHttp } from '@/api/adminHttp';
import { createIntent } from '@/api/idempotency';
import { ApiError } from '@/api/errors';
import { createTenantsApi, tenantListQuery } from '@/api/live/platform/tenants';

type Call = { url: string; init: RequestInit };

const json = (status: number, body: unknown) =>
  new Response(body === null ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': status >= 400 ? 'application/problem+json' : 'application/json' },
  });

function setup(responses: Response[]) {
  const calls: Call[] = [];
  const queue = [...responses];
  const fetchMock = vi.fn(async (url: RequestInfo | URL, init: RequestInit = {}) => {
    calls.push({ url: String(url), init });
    if (String(url).endsWith('/csrf-cookie')) return new Response(null, { status: 204 });
    const next = queue.shift();
    if (!next) throw new Error('no response queued');
    return next;
  });
  const http = createAdminHttp({ baseUrl: '', fetch: fetchMock as unknown as typeof fetch, readCookie: () => 'tok' });
  return { api: createTenantsApi(http), apiCalls: () => calls.filter((c) => !c.url.endsWith('/csrf-cookie')) };
}
const hdr = (c: Call, n: string) => (c.init.headers as Record<string, string>)[n];

const apiTenant = (id: string) => ({
  id, name: id, slug: id, status: 'active', country: 'KE', owner: null, plan: null, mrr: null,
  companies_count: 0, users_count: 0, created_at: '2026-11-01T00:00:00+00:00', last_activity_at: null, trial_ends_at: null,
});

describe('tenants list query', () => {
  it('drops empty filters and keeps the rest', () => {
    expect(tenantListQuery({ status: 'trial', plan: '', country: 'ke', q: ' acme ', sort: 'name' })).toEqual({ status: 'trial', country: 'KE', q: 'acme', sort: 'name' });
    expect(tenantListQuery({})).toEqual({});
  });
});

describe('tenants api', () => {
  it('sends filters as query params and follows the cursor for "Load more"', async () => {
    const { api, apiCalls } = setup([
      json(200, { data: [apiTenant('a')], meta: { next_cursor: 'c2', prev_cursor: null, limit: 1 } }),
      json(200, { data: [apiTenant('b')], meta: { next_cursor: null, prev_cursor: 'c1', limit: 1 } }),
    ]);
    const first = await api.listTenants({ status: 'trial', q: 'acme' });
    expect(apiCalls()[0].url).toBe('/api/admin/tenants?status=trial&q=acme');
    expect(first.rows.map((r) => r.id)).toEqual(['a']);
    expect(first.nextCursor).toBe('c2');
    const second = await api.listTenants({ status: 'trial', q: 'acme' }, first.nextCursor ?? undefined);
    expect(apiCalls()[1].url).toBe('/api/admin/tenants?status=trial&q=acme&cursor=c2');
    expect(second.nextCursor).toBeNull();
  });

  it('provisions with an Idempotency-Key, reuses it on a retry of the same intent, and returns the owner invitation', async () => {
    const created = { ...apiTenant('t9'), owner_invitation: { email: 'ann@acme.test', expires_at: '2026-11-25T00:00:00+00:00' } };
    const { api, apiCalls } = setup([json(500, { title: 'x', status: 500, code: 'server_error' }), json(201, { data: created })]);
    const intent = createIntent();
    const body = { organisation_name: 'Acme', country: 'KE', owner_email: 'ann@acme.test', owner_name: 'Ann', plan_code: 'pro', billing: 'trial' as const, trial_days: 14 };
    await expect(api.provisionTenant(body, intent)).rejects.toBeInstanceOf(ApiError);
    const result = await api.provisionTenant(body, intent);
    const [a, b] = apiCalls();
    expect(a.url).toBe('/api/admin/tenants');
    expect(hdr(a, 'Idempotency-Key')).toBeTruthy();
    expect(hdr(b, 'Idempotency-Key')).toBe(hdr(a, 'Idempotency-Key'));
    expect(JSON.parse(String(b.init.body))).toEqual(body);
    expect(result.tenant.id).toBe('t9');
    expect(result.ownerInvitation).toEqual({ email: 'ann@acme.test', expiresAt: '2026-11-25T00:00:00+00:00' });
    // The next intent gets a fresh key.
    expect(intent.key).not.toBe(hdr(a, 'Idempotency-Key'));
  });

  it('posts a lifecycle change with the target and reason', async () => {
    const { api, apiCalls } = setup([json(200, { data: apiTenant('t1') })]);
    await api.changeLifecycle('t1', 'read_only', 'unpaid');
    expect(apiCalls()[0].url).toBe('/api/admin/tenants/t1/lifecycle');
    expect(JSON.parse(String(apiCalls()[0].init.body))).toEqual({ to: 'read_only', reason: 'unpaid' });
  });

  it('requests deletion through the compliance endpoint, which answers with an approval', async () => {
    const { api, apiCalls } = setup([json(202, { data: { id: 'ap1', status: 'pending' } })]);
    const approval = await api.requestDeletion('t1', 'closing');
    expect(apiCalls()[0].url).toBe('/api/admin/tenants/t1/deletion');
    expect(approval.id).toBe('ap1');
  });

  it('reads billing status, and a tenant with no subscription as null', async () => {
    const { api } = setup([json(200, { stripe_configured: false }), json(200, { data: null })]);
    expect(await api.billingStatus()).toEqual({ stripeConfigured: false });
    expect(await api.subscription('t1')).toBeNull();
  });
});

describe('impersonation support', () => {
  it('is hidden when the endpoint does not exist (404) and offered when the route answers anything else', async () => {
    const missing = setup([json(404, { title: 'Not found', status: 404, code: 'not_found' })]);
    expect(await missing.api.impersonationAvailable('t1')).toBe(false);
    const present = setup([json(405, { title: 'Method not allowed', status: 405, code: 'method_not_allowed' })]);
    expect(await present.api.impersonationAvailable('t1')).toBe(true);
  });

  it('starts a session and returns the url to open', async () => {
    const { api, apiCalls } = setup([json(201, { session_id: 's1', ticket: 'tk', url: 'https://app.test/impersonate?ticket=tk' })]);
    const out = await api.startImpersonation('t1', { user_id: 'u1', mode: 'read', reason: 'Ticket 1' });
    expect(apiCalls()[0].url).toBe('/api/admin/tenants/t1/impersonations');
    expect(hdr(apiCalls()[0], 'Idempotency-Key')).toBeTruthy();
    expect(out.url).toBe('https://app.test/impersonate?ticket=tk');
  });
});
