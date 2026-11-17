import { describe, it, expect } from 'vitest';
import { adminGuardTarget, ADMIN_NAV, canDo, toAdminSession, visibleAdminNav, type ApiAdminSession } from '@/api/adapters/platform/session';
import { ApiError } from '@/api/errors';

const api = (over: Partial<ApiAdminSession> = {}): ApiAdminSession => ({ id: 'a1', name: 'Sara Njeri', email: 'sara@vivacom.test', role: 'support', can: ['tenants.view', 'ops.view'], ...over });

describe('admin session adapter', () => {
  it('maps the API session and leaves nothing invented', () => {
    const s = toAdminSession(api());
    expect(s).toMatchObject({ id: 'a1', name: 'Sara Njeri', email: 'sara@vivacom.test', role: 'support', roleTitle: 'Support', initials: 'SN' });
    expect(s.can).toEqual(['tenants.view', 'ops.view']);
  });

  it('tolerates a missing abilities list', () => {
    expect(toAdminSession(api({ can: undefined as unknown as string[] })).can).toEqual([]);
  });

  it('answers ability questions from the list, and an unlisted ability is a no', () => {
    const s = toAdminSession(api());
    expect(canDo(s, 'tenants.view')).toBe(true);
    expect(canDo(s, 'staff.manage')).toBe(false);
    expect(canDo(s, undefined)).toBe(true);
  });
});

describe('navigation by ability', () => {
  const labels = (can: string[]) => visibleAdminNav(can).flatMap((s) => s.items.map((i) => i.label));

  it('maps each console area to its ability', () => {
    const byTo = Object.fromEntries(ADMIN_NAV.flatMap((s) => s.items).map((i) => [i.to, i.ability]));
    expect(byTo).toMatchObject({
      '/admin': undefined,
      '/admin/tenants': 'tenants.view',
      '/admin/plans': 'plans.view',
      '/admin/coupons': 'plans.view',
      '/admin/billing': 'billing.view',
      '/admin/growth': 'growth.manage',
      '/admin/support': 'tenants.view',
      '/admin/ops': 'ops.view',
      '/admin/flags': 'ops.view',
      '/admin/staff': 'staff.manage',
      '/admin/compliance': 'compliance.manage',
    });
  });

  it('hides what the role cannot open and drops sections left empty', () => {
    const l = labels(['tenants.view']);
    expect(l).toContain('Tenants');
    expect(l).not.toContain('Staff and security');
    expect(l).not.toContain('Billing operations');
    expect(visibleAdminNav(['tenants.view']).every((s) => s.items.length > 0)).toBe(true);
    expect(visibleAdminNav([]).map((s) => s.title)).toEqual(['Platform']);
  });

  it('shows everything an owner holds and hides the mock-only partners page in live mode', () => {
    const all = ['tenants.view', 'plans.view', 'billing.view', 'growth.manage', 'ops.view', 'staff.manage', 'compliance.manage'];
    const l = labels(all);
    expect(l).toContain('Staff and security');
    expect(l).toContain('Compliance and GDPR');
    expect(l).not.toContain('Partners and resellers');
  });
});

describe('route guard decisions', () => {
  const err = (status: number, code: string) => new ApiError({ status, code, detail: '', requestId: null });
  const from = { pathname: '/admin/tenants', search: '?q=acme' };

  it('lets a signed-in session through', () => {
    expect(adminGuardTarget(null, from)).toEqual({ kind: 'allow' });
  });
  it('401 admin_two_factor_required goes to the two-factor page', () => {
    expect(adminGuardTarget(err(401, 'admin_two_factor_required'), from)).toEqual({ kind: 'redirect', to: '/admin/two-factor' });
  });
  it('an idle 401 goes to sign-in and remembers the way back', () => {
    expect(adminGuardTarget(err(401, 'unauthenticated'), from)).toEqual({ kind: 'redirect', to: '/admin/login', next: '/admin/tenants?q=acme' });
  });
  it('403 admin_ip_blocked goes to the blocked page', () => {
    expect(adminGuardTarget(err(403, 'admin_ip_blocked'), from)).toEqual({ kind: 'redirect', to: '/admin/blocked' });
  });
  it('anything else is shown as an error, not a redirect loop', () => {
    expect(adminGuardTarget(err(500, 'server_error'), from)).toEqual({ kind: 'error' });
    expect(adminGuardTarget(new Error('x'), from)).toEqual({ kind: 'error' });
  });
});
