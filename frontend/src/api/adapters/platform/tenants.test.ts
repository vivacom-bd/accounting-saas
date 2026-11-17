import { describe, it, expect } from 'vitest';
import { toTenantRow, toTenantDetail, toSubscription, type ApiTenant, type ApiTenantDetail } from '@/api/adapters/platform/tenants';
import {
  validateProvision, toProvisionBody, emptyProvisionForm, validateImpersonation, toImpersonationBody, toSubscribeBody, validateSubscribe, emptySubscribeForm,
} from '@/api/adapters/platform/tenantForms';

const api: ApiTenant = {
  id: 't1', name: 'Acme', slug: 'acme', status: 'trial', country: 'KE',
  owner: { name: 'Ann', email: 'ann@acme.test' }, plan: { code: 'pro', name: 'Professional' }, mrr: null,
  companies_count: 2, users_count: 5, created_at: '2026-11-01T00:00:00+00:00', last_activity_at: null, trial_ends_at: '2026-11-15T00:00:00+00:00',
};

describe('tenant adapters', () => {
  it('maps a directory row, leaving what the API does not provide undefined', () => {
    const row = toTenantRow(api);
    expect(row).toMatchObject({ id: 't1', status: 'trial', ownerEmail: 'ann@acme.test', planCode: 'pro', companiesCount: 2, usersCount: 5 });
    expect(row.lastActivityAt).toBeUndefined();
    expect(row).not.toHaveProperty('healthScore');
  });

  it('handles a tenant with no owner and no plan', () => {
    const row = toTenantRow({ ...api, owner: null, plan: null });
    expect(row.ownerEmail).toBeUndefined();
    expect(row.planCode).toBeUndefined();
  });

  it('maps limits against usage, an unlimited metric keeps limit null, and the timeline and notes', () => {
    const d: ApiTenantDetail = {
      ...api,
      limits_usage: { companies: { used: 2, limit: 3 }, users: { used: 5, limit: null } },
      lifecycle_timeline: [{ id: 'e1', from: 'trial', to: 'active', reason: 'paid', actor_type: 'admin', actor_id: 'a1', occurred_at: '2026-11-02T00:00:00+00:00' }],
      notes: [{ id: 'n1', body: 'call back', pinned: true, admin: { id: 'a1', name: 'Sam' }, created_at: '2026-11-03T00:00:00+00:00' }],
    };
    const t = toTenantDetail(d);
    expect(t.limits).toEqual([{ metric: 'companies', used: 2, limit: 3 }, { metric: 'users', used: 5, limit: null }]);
    expect(t.timeline[0]).toMatchObject({ from: 'trial', to: 'active', reason: 'paid' });
    expect(t.notes[0]).toMatchObject({ pinned: true, authorName: 'Sam' });
  });

  it('keeps subscription money as the integer minor units the API sent', () => {
    const s = toSubscription({
      id: 's1', tenant_id: 't1', plan_code: 'pro', plan_name: 'Professional', plan_version: 2, collection: 'offline', status: 'active', interval: 'month',
      currency: 'KES', custom_amount: null, quantity: 1, monthly_amount: 499900, trial_ends_at: null, current_period_end: '2026-12-01T00:00:00+00:00',
      cancel_at: null, pause_until: null, coupon_code: null, stripe_subscription_id: null, created_at: '2026-11-01T00:00:00+00:00',
    });
    expect(s?.monthlyAmount).toBe(499900);
    expect(Number.isInteger(s?.monthlyAmount)).toBe(true);
    expect(s?.currency).toBe('KES');
    expect(toSubscription(null)).toBeNull();
  });
});

describe('provision form', () => {
  const ok = { ...emptyProvisionForm(), organisationName: 'Acme', country: 'ke', ownerEmail: 'ann@acme.test', ownerName: 'Ann', planCode: 'pro' };

  it('requires every field and reports each by name', () => {
    const errors = validateProvision(emptyProvisionForm());
    expect(Object.keys(errors).sort()).toEqual(['country', 'organisationName', 'ownerEmail', 'ownerName', 'planCode']);
  });

  it('rejects a bad email, a country that is not two letters and trial days out of range', () => {
    const errors = validateProvision({ ...ok, ownerEmail: 'nope', country: 'KEN', billing: 'trial', trialDays: '120' });
    expect(errors.ownerEmail).toBeTruthy();
    expect(errors.country).toBeTruthy();
    expect(errors.trialDays).toBeTruthy();
  });

  it('builds the trial body with an upper-cased country and no offline fields', () => {
    expect(validateProvision(ok)).toEqual({});
    expect(toProvisionBody({ ...ok, trialDays: '14' })).toEqual({
      organisation_name: 'Acme', country: 'KE', owner_email: 'ann@acme.test', owner_name: 'Ann', plan_code: 'pro', billing: 'trial', trial_days: 14,
    });
  });

  it('builds the offline body with the amount as integer minor units of the currency', () => {
    const form = { ...ok, billing: 'offline' as const, currency: 'KES', interval: 'year' as const, customAmount: '1,500.50', note: 'invoiced' };
    expect(validateProvision(form)).toEqual({});
    expect(toProvisionBody(form)).toEqual({
      organisation_name: 'Acme', country: 'KE', owner_email: 'ann@acme.test', owner_name: 'Ann', plan_code: 'pro', billing: 'offline',
      currency: 'KES', interval: 'year', custom_amount: 150050, note: 'invoiced',
    });
  });

  it('refuses an amount that is not a number', () => {
    expect(validateProvision({ ...ok, billing: 'offline', customAmount: 'abc' }).customAmount).toBeTruthy();
  });
});

describe('subscribe form', () => {
  it('needs a plan, and Stripe collection is not accepted while Stripe is not connected', () => {
    expect(validateSubscribe(emptySubscribeForm(), { stripeConfigured: true }).planCode).toBeTruthy();
    const f = { ...emptySubscribeForm(), planCode: 'pro', collection: 'stripe' as const };
    expect(validateSubscribe(f, { stripeConfigured: false }).collection).toBeTruthy();
    expect(validateSubscribe({ ...f, collection: 'offline' }, { stripeConfigured: false })).toEqual({});
    expect(toSubscribeBody({ ...f, collection: 'offline', currency: 'EUR', customAmount: '10.00' })).toMatchObject({ collection: 'offline', currency: 'EUR', custom_amount: 1000 });
  });
});

describe('impersonation form', () => {
  const base = { userId: 'u1', mode: 'read' as const, reason: 'Ticket 4411', approvalId: '', ownerOverride: false };

  it('requires a user and a reason', () => {
    expect(validateImpersonation({ ...base, reason: '  ' }).reason).toBeTruthy();
    expect(validateImpersonation({ ...base, userId: '' }).userId).toBeTruthy();
    expect(validateImpersonation(base)).toEqual({});
  });

  it('write mode asks for an approval id, read mode does not send one', () => {
    expect(validateImpersonation({ ...base, mode: 'write' }).approvalId).toBeTruthy();
    expect(validateImpersonation({ ...base, mode: 'write', approvalId: 'ap1' })).toEqual({});
    expect(toImpersonationBody({ ...base, approvalId: 'ap1' })).toEqual({ user_id: 'u1', mode: 'read', reason: 'Ticket 4411' });
    expect(toImpersonationBody({ ...base, mode: 'write', approvalId: 'ap1', ownerOverride: true })).toEqual({
      user_id: 'u1', mode: 'write', reason: 'Ticket 4411', approval_id: 'ap1', owner_override: true,
    });
  });
});
