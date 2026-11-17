/**
 * Plans, add-ons and coupons for the admin console (plan Task 22). Pure: no React, no network.
 * Every price is integer minor units per currency, read from typed text with the shared money parser (never a float);
 * the form rules mirror the backend's (Prices, PlanLimits, PlanEntitlements, CreateCoupon) so a mistake is told in words
 * before the round trip. The server still decides.
 */
import { formatMoney, getCurrencyMinorUnits, parseMoneyText, rawMoneyText } from '@/lib/format';

export const CURRENCIES = ['USD', 'KES', 'EUR'] as const;
export type BillingCurrency = (typeof CURRENCIES)[number];
export const INTERVALS = ['monthly', 'annual'] as const;
export type Interval = (typeof INTERVALS)[number];

export const LIMIT_KEYS = ['companies', 'users', 'api_clients', 'journal_lines_per_month', 'storage_mb', 'api_calls_per_day'] as const;
export type LimitKey = (typeof LIMIT_KEYS)[number];
export const LIMIT_LABELS: Record<LimitKey, string> = {
  companies: 'Companies',
  users: 'Users',
  api_clients: 'API clients',
  journal_lines_per_month: 'Journal lines per month',
  storage_mb: 'Storage (MB)',
  api_calls_per_day: 'API calls per day',
};

export const ENTITLEMENT_KEYS = ['multi_currency', 'independent_approvals', 'fixed_assets', 'budgets', 'api_access', 'consolidation', 'audit_chain_export', 'custom_roles'] as const;
export type EntitlementKey = (typeof ENTITLEMENT_KEYS)[number];
export const ENTITLEMENT_LABELS: Record<EntitlementKey, string> = {
  multi_currency: 'Multi-currency',
  independent_approvals: 'Independent approvals',
  fixed_assets: 'Fixed assets',
  budgets: 'Budgets',
  api_access: 'API access',
  consolidation: 'Consolidation',
  audit_chain_export: 'Audit chain export',
  custom_roles: 'Custom roles',
};

export const ADDON_KINDS = ['company', 'user_pack', 'storage', 'metered_api'] as const;

/* API shapes (GET /plans, /addons, /coupons are typed `unknown` in schema-admin.d.ts, so these are written out by hand). */

type PriceRow = { monthly: number | null; annual: number | null };
export interface ApiPlanVersion {
  id: string;
  plan_id: string;
  version: number;
  prices: Partial<Record<BillingCurrency, PriceRow>>;
  limits: Partial<Record<LimitKey, number | null>>;
  entitlements: Partial<Record<EntitlementKey, boolean>>;
  trial_days: number;
  stripe_product_id: string | null;
  effective_from: string;
}
export interface ApiPlan {
  id: string;
  code: string;
  name: string;
  description: string | null;
  is_active: boolean;
  sort: number;
  current_version: ApiPlanVersion | null;
  scheduled_versions: ApiPlanVersion[];
  tenant_count: number;
}
export interface ApiAddon {
  id: string;
  code: string;
  name: string;
  kind: string;
  price: Partial<Record<BillingCurrency, number>>;
  unit_label: string | null;
  stripe_price_id: string | null;
  is_active: boolean;
}
export interface ApiCoupon {
  id: string;
  code: string;
  percent: number | null;
  amount: number | null;
  currency: string | null;
  duration: 'once' | 'repeating' | 'forever';
  duration_months: number | null;
  restrictions: { plan_codes?: string[]; new_customers_only?: boolean } | null;
  max_redemptions: number | null;
  redeemed_count: number;
  expires_at: string | null;
  stripe_coupon_id: string | null;
  is_active: boolean;
}

export type Fields = Record<string, string>;
export type Built<T> = { ok: true; body: T } | { ok: false; errors: Fields };

/* money text */

type Amount = { value: number | null } | { error: string };

/** Blank is "no price" (null); anything else must be an amount of zero or more with no more decimals than the currency has. */
function readAmount(text: string, currency: string, blankIsNull: boolean): Amount {
  if (text.trim() === '') return blankIsNull ? { value: null } : { error: 'Enter an amount.' };
  const n = parseMoneyText(text, currency);
  if (n === null) return { error: `Enter an amount in ${currency}, with at most ${getCurrencyMinorUnits(currency)} decimals.` };
  if (n < 0) return { error: 'An amount cannot be negative.' };
  return { value: n };
}

export function formatPlanPrice(minor: number | null | undefined, currency: string): string {
  return minor === null || minor === undefined ? 'Custom quote' : formatMoney(minor, currency);
}

/* plan versions */

export interface VersionForm {
  prices: Record<BillingCurrency, { monthly: string; annual: string }>;
  limits: Record<LimitKey, { unlimited: boolean; value: string }>;
  entitlements: Record<EntitlementKey, boolean>;
  trialDays: string;
  effectiveFrom: string;
}

export interface VersionBody {
  prices: Record<string, PriceRow>;
  limits: Record<LimitKey, number | null>;
  entitlements: Record<EntitlementKey, boolean>;
  trial_days: number;
  effective_from?: string;
}

export function emptyVersionForm(today: string): VersionForm {
  return {
    prices: { USD: { monthly: '', annual: '' }, KES: { monthly: '', annual: '' }, EUR: { monthly: '', annual: '' } },
    limits: Object.fromEntries(LIMIT_KEYS.map((k) => [k, { unlimited: false, value: '' }])) as VersionForm['limits'],
    entitlements: Object.fromEntries(ENTITLEMENT_KEYS.map((k) => [k, false])) as VersionForm['entitlements'],
    trialDays: '0',
    effectiveFrom: today,
  };
}

/** A new version starts from the one in force: same minor units, shown as editable text. */
export function formFromVersion(v: ApiPlanVersion, today: string): VersionForm {
  const f = emptyVersionForm(today);
  for (const c of CURRENCIES) {
    const row = v.prices[c];
    f.prices[c] = {
      monthly: row?.monthly == null ? '' : rawMoneyText(row.monthly, c),
      annual: row?.annual == null ? '' : rawMoneyText(row.annual, c),
    };
  }
  for (const k of LIMIT_KEYS) {
    const n = v.limits[k];
    f.limits[k] = n == null ? { unlimited: true, value: '' } : { unlimited: false, value: String(n) };
  }
  for (const k of ENTITLEMENT_KEYS) f.entitlements[k] = v.entitlements[k] === true;
  f.trialDays = String(v.trial_days);
  return f;
}

const wholeNumber = (text: string): number | null => (/^\d{1,9}$/.test(text.trim()) ? Number(text.trim()) : null);

export function buildVersionBody(form: VersionForm, today: string): Built<VersionBody> {
  const errors: Fields = {};

  const prices: Record<string, PriceRow> = {};
  for (const c of CURRENCIES) {
    const row: PriceRow = { monthly: null, annual: null };
    for (const i of INTERVALS) {
      const r = readAmount(form.prices[c][i], c, true);
      if ('error' in r) errors[`prices.${c}.${i}`] = r.error;
      else row[i] = r.value;
    }
    prices[c] = row;
  }

  const limits = {} as Record<LimitKey, number | null>;
  for (const k of LIMIT_KEYS) {
    const l = form.limits[k];
    if (l.unlimited) {
      limits[k] = null;
      continue;
    }
    const n = wholeNumber(l.value);
    if (n === null) errors[`limits.${k}`] = 'Enter a whole number, zero or more, or tick Unlimited.';
    else limits[k] = n;
  }

  const trial = wholeNumber(form.trialDays);
  if (trial === null || trial > 365) errors.trialDays = 'Trial days is a whole number from 0 to 365.';

  const effective = form.effectiveFrom.trim();
  if (effective !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(effective)) errors.effectiveFrom = 'Enter a date.';
  else if (effective !== '' && effective < today) errors.effectiveFrom = 'A version cannot start in the past.';

  if (Object.keys(errors).length > 0) return { ok: false, errors };
  const body: VersionBody = { prices, limits, entitlements: { ...form.entitlements }, trial_days: trial as number };
  if (effective !== '') body.effective_from = effective;
  return { ok: true, body };
}

/** A version whose date has come is in force (or was): it is history and is never edited, only superseded. */
export function isVersionEffective(v: Pick<ApiPlanVersion, 'effective_from'>, now: Date): boolean {
  return new Date(v.effective_from).getTime() <= now.getTime();
}

/* add-ons */

export interface AddonForm {
  code: string;
  name: string;
  kind: string;
  unitLabel: string;
  prices: Record<BillingCurrency, string>;
}
export interface AddonBody {
  name: string;
  kind: string;
  price: Partial<Record<BillingCurrency, number>>;
  unit_label?: string;
}

export function addonFormFrom(a?: ApiAddon): AddonForm {
  return {
    code: a?.code ?? '',
    name: a?.name ?? '',
    kind: a?.kind ?? 'user_pack',
    unitLabel: a?.unit_label ?? '',
    prices: Object.fromEntries(CURRENCIES.map((c) => [c, a?.price[c] === undefined ? '' : rawMoneyText(a.price[c] as number, c)])) as AddonForm['prices'],
  };
}

export function buildAddonBody(form: AddonForm): Built<AddonBody> & { code?: string } {
  const errors: Fields = {};
  const code = form.code.trim();
  if (!/^[a-z][a-z0-9_]{1,47}$/.test(code)) errors.code = 'Use 2 to 48 lowercase letters, digits or underscores, starting with a letter.';
  if (form.name.trim() === '') errors.name = 'Enter a name.';
  if (!(ADDON_KINDS as readonly string[]).includes(form.kind)) errors.kind = 'Pick a kind.';
  const price: Partial<Record<BillingCurrency, number>> = {};
  for (const c of CURRENCIES) {
    const r = readAmount(form.prices[c], c, true);
    if ('error' in r) errors[`price.${c}`] = r.error;
    else if (r.value !== null) price[c] = r.value;
  }
  if (Object.keys(price).length === 0 && !Object.keys(errors).some((k) => k.startsWith('price.'))) errors.price = 'Give a price in at least one currency.';
  if (Object.keys(errors).length > 0) return { ok: false, errors };
  const body: AddonBody = { name: form.name.trim(), kind: form.kind, price };
  if (form.unitLabel.trim() !== '') body.unit_label = form.unitLabel.trim();
  return { ok: true, body, code };
}

/* coupons */

export interface CouponForm {
  code: string;
  mode: 'percent' | 'amount';
  percent: string;
  amount: string;
  currency: BillingCurrency;
  duration: 'once' | 'repeating' | 'forever';
  durationMonths: string;
  maxRedemptions: string;
  expiresAt: string;
  planCodes: string;
  newCustomersOnly: boolean;
}
export interface CouponBody {
  code: string;
  percent?: number;
  amount?: number;
  currency?: string;
  duration: string;
  duration_months?: number;
  max_redemptions?: number;
  expires_at?: string;
  restrictions?: { plan_codes?: string[]; new_customers_only?: boolean };
}

export function emptyCouponForm(): CouponForm {
  return {
    code: '', mode: 'percent', percent: '', amount: '', currency: 'USD', duration: 'once', durationMonths: '',
    maxRedemptions: '', expiresAt: '', planCodes: '', newCustomersOnly: false,
  };
}

export function buildCouponBody(form: CouponForm): Built<CouponBody> {
  const errors: Fields = {};
  const code = form.code.trim().toUpperCase();
  if (!/^[A-Z0-9][A-Z0-9_-]{2,47}$/.test(code)) errors.code = 'A code is 3 to 48 letters, digits, dashes or underscores.';

  const body: CouponBody = { code, duration: form.duration };
  if (form.mode === 'percent') {
    const p = wholeNumber(form.percent);
    if (p === null || p < 1 || p > 100) errors.percent = 'The percentage is a whole number from 1 to 100.';
    else body.percent = p;
  } else {
    const r = readAmount(form.amount, form.currency, false);
    if ('error' in r) errors.amount = r.error;
    else if (r.value === null || r.value < 1) errors.amount = 'The amount must be more than zero.';
    else {
      body.amount = r.value;
      body.currency = form.currency;
    }
  }

  if (form.duration === 'repeating') {
    const m = wholeNumber(form.durationMonths);
    if (m === null || m < 1 || m > 36) errors.durationMonths = 'A repeating coupon lasts 1 to 36 months.';
    else body.duration_months = m;
  }

  if (form.maxRedemptions.trim() !== '') {
    const n = wholeNumber(form.maxRedemptions);
    if (n === null || n < 1) errors.maxRedemptions = 'Maximum redemptions is 1 or more, or leave it empty for no limit.';
    else body.max_redemptions = n;
  }

  if (form.expiresAt.trim() !== '') {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.expiresAt.trim())) errors.expiresAt = 'Enter a date.';
    else body.expires_at = form.expiresAt.trim();
  }

  const planCodes = form.planCodes.split(/[\s,]+/).filter((c) => c !== '');
  if (planCodes.some((c) => !/^[a-z][a-z0-9_]{1,31}$/.test(c))) errors.planCodes = 'Plan codes are lowercase letters, digits and underscores.';
  if (planCodes.length > 0 || form.newCustomersOnly) {
    body.restrictions = {};
    if (planCodes.length > 0) body.restrictions.plan_codes = planCodes;
    if (form.newCustomersOnly) body.restrictions.new_customers_only = true;
  }

  return Object.keys(errors).length > 0 ? { ok: false, errors } : { ok: true, body };
}

export function describeDiscount(c: Pick<ApiCoupon, 'percent' | 'amount' | 'currency'>): string {
  if (c.percent !== null) return `${c.percent}% off`;
  return `${formatMoney(c.amount, c.currency ?? 'USD')} off`;
}

export function describeDuration(c: Pick<ApiCoupon, 'duration' | 'duration_months'>): string {
  if (c.duration === 'once') return 'Once';
  if (c.duration === 'forever') return 'Forever';
  return `${c.duration_months} months`;
}
