import { describe, it, expect, vi } from 'vitest';
import { createAdminHttp } from '@/api/adminHttp';
import { ApiError } from '@/api/errors';
import { createIntent } from '@/api/idempotency';

type Call = { url: string; init: RequestInit };

const json = (status: number, body: unknown) =>
  new Response(body === null ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': status >= 400 ? 'application/problem+json' : 'application/json' },
  });

function setup(responses: Array<Response>, cookies: Record<string, string> = { 'XSRF-TOKEN-ADMIN': 'admin-tok', 'XSRF-TOKEN': 'tenant-tok' }) {
  const calls: Call[] = [];
  const queue = [...responses];
  const fetchMock = vi.fn(async (url: RequestInfo | URL, init: RequestInit = {}) => {
    calls.push({ url: String(url), init });
    if (String(url).endsWith('/csrf-cookie')) return new Response(null, { status: 204 });
    const next = queue.shift();
    if (!next) throw new Error('no response queued');
    return next;
  });
  const client = createAdminHttp({ baseUrl: '', fetch: fetchMock as unknown as typeof fetch, readCookie: (n) => cookies[n] });
  const apiCalls = () => calls.filter((c) => !c.url.endsWith('/csrf-cookie'));
  return { client, calls, apiCalls };
}
const hdr = (c: Call, name: string) => (c.init.headers as Record<string, string>)[name];

describe('admin http client', () => {
  it('talks to /api/admin and takes its CSRF cookie from /api/admin/csrf-cookie', async () => {
    const { client, calls } = setup([json(200, { data: { id: 'a1' } }), json(200, { two_factor: 'challenge' })]);
    await client.get('/session');
    await client.post('/auth/login', { body: { email: 'a@b.c', password: 'x' } });
    expect(calls[0].url).toBe('/api/admin/session');
    expect(calls.map((c) => c.url)).toContain('/api/admin/csrf-cookie');
    expect(calls.map((c) => c.url)).not.toContain('/sanctum/csrf-cookie');
  });

  it('sends X-XSRF-TOKEN from the admin cookie, never the tenant one', async () => {
    const { client, apiCalls } = setup([json(200, { two_factor: 'challenge' })]);
    await client.post('/auth/login', { body: { email: 'a@b.c', password: 'x' } });
    expect(hdr(apiCalls()[0], 'X-XSRF-TOKEN')).toBe('admin-tok');
  });

  it('sends no token at all when only the tenant cookie exists', async () => {
    const { client, apiCalls } = setup([json(200, { two_factor: 'challenge' })], { 'XSRF-TOKEN': 'tenant-tok' });
    await client.post('/auth/login', { body: { email: 'a@b.c', password: 'x' } });
    expect(hdr(apiCalls()[0], 'X-XSRF-TOKEN')).toBeUndefined();
  });

  it('sends an Idempotency-Key on mutations (reused for the same intent) and none on reads', async () => {
    const { client, apiCalls } = setup([json(200, { data: {} }), json(500, { code: 'server_error' }), json(200, { recovery_codes: [] })]);
    await client.get('/session');
    const intent = createIntent();
    await expect(client.post('/auth/two-factor/confirm', { body: { code: '123456' }, intent })).rejects.toBeInstanceOf(ApiError);
    await client.post('/auth/two-factor/confirm', { body: { code: '123456' }, intent });
    const [read, first, second] = apiCalls();
    expect(hdr(read, 'Idempotency-Key')).toBeUndefined();
    expect(hdr(first, 'Idempotency-Key')).toBeTruthy();
    expect(hdr(second, 'Idempotency-Key')).toBe(hdr(first, 'Idempotency-Key'));
  });

  it('turns problem+json into an ApiError carrying the code', async () => {
    const { client } = setup([json(401, { code: 'admin_two_factor_required', detail: 'Enter your code.' })]);
    const e = await client.get('/session').catch((x: unknown) => x);
    expect(e).toBeInstanceOf(ApiError);
    expect(e).toMatchObject({ status: 401, code: 'admin_two_factor_required' });
  });
});
