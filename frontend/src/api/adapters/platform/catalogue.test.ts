import { describe, it, expect } from 'vitest';
import {
  buildVersionBody, emptyVersionForm, formFromVersion, isVersionEffective, buildCouponBody, emptyCouponForm, buildAddonBody, formatPlanPrice,
  type ApiPlanVersion, type VersionForm,
} from '@/api/adapters/platform/catalogue';

const TODAY = '2026-11-18';

const filled = (over: Partial<VersionForm> = {}): VersionForm => {
  const f = emptyVersionForm(TODAY);
  f.prices.USD = { monthly: '49', annual: '490' };
  f.prices.KES = { monthly: '6,500', annual: '65000.50' };
  f.prices.EUR = { monthly: '', annual: '' };
  for (const k of Object.keys(f.limits) as (keyof VersionForm['limits'])[]) f.limits[k] = { unlimited: false, value: '10' };
  return { ...f, ...over };
};

describe('plan version form -> request body', () => {
  it('keeps every price as integer minor units per currency, KES included', () => {
    const r = buildVersionBody(filled(), TODAY);
    expect(r.ok).toBe(true);
    if (!r.ok) return;
    expect(r.body.prices).toEqual({
      USD: { monthly: 4900, annual: 49000 },
      KES: { monthly: 650000, annual: 6500050 },
      EUR: { monthly: null, annual: null },
    });
    for (const row of Object.values(r.body.prices)) for (const n of [row.monthly, row.annual]) expect(n === null || Number.isInteger(n)).toBe(true);
  });

  it('refuses text that is not an amount, a negative price and extra decimals instead of rounding', () => {
    const f = filled();
    f.prices.USD = { monthly: 'abc', annual: '-5' };
    f.prices.KES = { monthly: '1.234', annual: '' };
    const r = buildVersionBody(f, TODAY);
    expect(r.ok).toBe(false);
    if (r.ok) return;
    expect(r.errors['prices.USD.monthly']).toBeTruthy();
    expect(r.errors['prices.USD.annual']).toBeTruthy();
    expect(r.errors['prices.KES.monthly']).toBeTruthy();
  });

  it('sends unlimited as null and a typed limit as a whole number', () => {
    const f = filled();
    f.limits.users = { unlimited: false, value: '25' };
    f.limits.companies = { unlimited: true, value: '99' };
    const r = buildVersionBody(f, TODAY);
    expect(r.ok).toBe(true);
    if (!r.ok) return;
    expect(r.body.limits.users).toBe(25);
    expect(r.body.limits.companies).toBeNull();
    expect(Object.keys(r.body.limits).sort()).toEqual(['api_calls_per_day', 'api_clients', 'companies', 'journal_lines_per_month', 'storage_mb', 'users']);
  });

  it('refuses a fractional or negative limit', () => {
    const f = filled();
    f.limits.users = { unlimited: false, value: '2.5' };
    f.limits.api_clients = { unlimited: false, value: '-1' };
    const r = buildVersionBody(f, TODAY);
    expect(r.ok).toBe(false);
    if (r.ok) return;
    expect(r.errors['limits.users']).toBeTruthy();
    expect(r.errors['limits.api_clients']).toBeTruthy();
  });

  it('sends every entitlement as a boolean', () => {
    const f = filled();
    f.entitlements.consolidation = true;
    const r = buildVersionBody(f, TODAY);
    expect(r.ok && Object.keys(r.body.entitlements).length).toBe(8);
    expect(r.ok && r.body.entitlements.consolidation).toBe(true);
    expect(r.ok && r.body.entitlements.fixed_assets).toBe(false);
  });

  it('checks trial days and that the effective date is not in the past', () => {
    expect(buildVersionBody(filled({ trialDays: '400' }), TODAY).ok).toBe(false);
    expect(buildVersionBody(filled({ trialDays: '1.5' }), TODAY).ok).toBe(false);
    const past = buildVersionBody(filled({ effectiveFrom: '2026-11-17' }), TODAY);
    expect(past.ok).toBe(false);
    if (!past.ok) expect(past.errors.effectiveFrom).toBeTruthy();
    const ok = buildVersionBody(filled({ trialDays: '14', effectiveFrom: '2026-12-01' }), TODAY);
    expect(ok.ok && ok.body.trial_days).toBe(14);
    expect(ok.ok && ok.body.effective_from).toBe('2026-12-01');
  });

  it('prefills a new version from the current one with the same minor units', () => {
    const v: ApiPlanVersion = {
      id: 'v1', plan_id: 'p', version: 2, effective_from: '2026-01-01T00:00:00+00:00', trial_days: 14, stripe_product_id: null,
      prices: { USD: { monthly: 4900, annual: null }, KES: { monthly: 650000, annual: 6500050 } },
      limits: { companies: null, users: 10, api_clients: 2, journal_lines_per_month: 1000, storage_mb: 500, api_calls_per_day: 100 },
      entitlements: { multi_currency: true },
    };
    const f = formFromVersion(v, TODAY);
    expect(f.prices.USD).toEqual({ monthly: '49.00', annual: '' });
    expect(f.prices.KES.annual).toBe('65000.50');
    expect(f.limits.companies.unlimited).toBe(true);
    expect(f.limits.users).toEqual({ unlimited: false, value: '10' });
    expect(f.entitlements.multi_currency).toBe(true);
    expect(f.entitlements.budgets).toBe(false);
    const back = buildVersionBody(f, TODAY);
    expect(back.ok && back.body.prices.KES).toEqual({ monthly: 650000, annual: 6500050 });
  });
});

describe('effective versions', () => {
  const v = (effective_from: string) => ({ effective_from }) as ApiPlanVersion;
  const now = new Date('2026-11-18T10:00:00Z');
  it('is read-only once its date has come, editable while it is still scheduled', () => {
    expect(isVersionEffective(v('2026-11-18T00:00:00+00:00'), now)).toBe(true);
    expect(isVersionEffective(v('2026-01-01T00:00:00+00:00'), now)).toBe(true);
    expect(isVersionEffective(v('2026-12-01T00:00:00+00:00'), now)).toBe(false);
  });
  it('shows a custom quote for a null price', () => {
    expect(formatPlanPrice(4900, 'USD')).toBe('$49.00');
    expect(formatPlanPrice(null, 'USD')).toBe('Custom quote');
    expect(formatPlanPrice(undefined, 'USD')).toBe('Custom quote');
  });
});

describe('add-on form', () => {
  it('turns a flat price per currency into minor units and refuses a bad code', () => {
    const ok = buildAddonBody({ code: 'extra_users', name: 'Extra users', kind: 'user_pack', unitLabel: 'seat', prices: { USD: '3.50', KES: '450', EUR: '' } });
    expect(ok.ok && ok.body.price).toEqual({ USD: 350, KES: 45000 });
    const bad = buildAddonBody({ code: 'Bad Code', name: '', kind: 'user_pack', unitLabel: '', prices: { USD: '', KES: '', EUR: '' } });
    expect(bad.ok).toBe(false);
    if (!bad.ok) expect(Object.keys(bad.errors)).toEqual(expect.arrayContaining(['code', 'name', 'price']));
  });
});

describe('coupon form -> request body', () => {
  const base = { ...emptyCouponForm(), code: 'welcome20' };

  it('a percent coupon sends percent only', () => {
    const r = buildCouponBody({ ...base, mode: 'percent', percent: '20', duration: 'once' });
    expect(r.ok && r.body).toMatchObject({ code: 'WELCOME20', percent: 20, duration: 'once' });
    expect(r.ok && 'amount' in r.body).toBe(false);
    expect(r.ok && 'currency' in r.body).toBe(false);
  });

  it('an amount coupon sends minor units and a currency, never a percent', () => {
    const r = buildCouponBody({ ...base, mode: 'amount', amount: '1,500.50', currency: 'KES', duration: 'forever' });
    expect(r.ok && r.body).toMatchObject({ amount: 150050, currency: 'KES' });
    expect(r.ok && 'percent' in r.body).toBe(false);
  });

  it('refuses a percent outside 1 to 100 and an amount of zero', () => {
    expect(buildCouponBody({ ...base, mode: 'percent', percent: '0' }).ok).toBe(false);
    expect(buildCouponBody({ ...base, mode: 'percent', percent: '101' }).ok).toBe(false);
    expect(buildCouponBody({ ...base, mode: 'percent', percent: '12.5' }).ok).toBe(false);
    expect(buildCouponBody({ ...base, mode: 'amount', amount: '0', currency: 'USD' }).ok).toBe(false);
  });

  it('repeating needs months; once and forever send none', () => {
    const rep = buildCouponBody({ ...base, mode: 'percent', percent: '10', duration: 'repeating', durationMonths: '' });
    expect(rep.ok).toBe(false);
    const ok = buildCouponBody({ ...base, mode: 'percent', percent: '10', duration: 'repeating', durationMonths: '3' });
    expect(ok.ok && ok.body.duration_months).toBe(3);
    const once = buildCouponBody({ ...base, mode: 'percent', percent: '10', duration: 'once', durationMonths: '3' });
    expect(once.ok && 'duration_months' in once.body).toBe(false);
  });

  it('checks the code shape, max redemptions and sends the expiry as a date', () => {
    expect(buildCouponBody({ ...base, code: 'ab', mode: 'percent', percent: '10' }).ok).toBe(false);
    expect(buildCouponBody({ ...base, mode: 'percent', percent: '10', maxRedemptions: '0' }).ok).toBe(false);
    const r = buildCouponBody({ ...base, mode: 'percent', percent: '10', maxRedemptions: '50', expiresAt: '2027-01-31' });
    expect(r.ok && r.body.max_redemptions).toBe(50);
    expect(r.ok && r.body.expires_at).toBe('2027-01-31');
  });
});
