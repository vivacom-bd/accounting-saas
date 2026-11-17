/**
 * Admin tenant API shapes -> what the live tenants screens use. Pure; no React, no network.
 *
 * The admin controllers return untyped arrays, so the generated schema-admin.d.ts types most of these responses as
 * `unknown`. The `Api*` types below are the shapes read off the controllers/resources (AdminTenantResource, ShowTenant,
 * TenantUsers, TenantCompanies, TenantNotes, TenantTimeline, TenantSubscriptionView); the live module casts at the
 * boundary and nowhere else. Fields the API does not provide (health score, MRR until subscriptions feed it, data
 * residency, DPA, tickets) are not modelled at all, so a screen cannot show an invented value.
 * Money is integer minor units and stays so.
 */
import type { LifecycleStatus } from '@/lib/tenantLifecycle';

export interface ApiTenant {
  id: string;
  name: string;
  slug: string;
  status: string;
  country: string | null;
  owner: { name: string | null; email: string | null } | null;
  plan: { code: string; name: string | null } | null;
  mrr: number | null;
  companies_count: number;
  users_count: number;
  created_at: string | null;
  last_activity_at: string | null;
  trial_ends_at: string | null;
}

export interface ApiLimitUsage {
  used: number;
  limit: number | null;
}

export interface ApiTimelineEvent {
  id: string;
  from: string;
  to: string;
  reason: string;
  actor_type: string;
  actor_id: string | null;
  occurred_at: string;
}

export interface ApiNote {
  id: string;
  body: string;
  pinned: boolean;
  admin: { id: string; name: string | null };
  created_at: string;
}

export interface ApiTenantDetail extends ApiTenant {
  limits_usage: Record<string, ApiLimitUsage>;
  lifecycle_timeline: ApiTimelineEvent[];
  notes: ApiNote[];
}

export interface ApiTenantUser {
  id: string;
  name: string;
  email: string;
  org_role: string;
  status: string;
  two_factor: boolean;
  last_login_at: string | null;
}

export interface ApiTenantCompany {
  id: string;
  name: string;
  status: string;
  base_currency: string;
  country: string | null;
  fiscal_year_start: string | null;
  open_periods: number;
  closed_periods: number;
  posted_journals: number;
}

export interface ApiAuditEvent {
  id: string;
  admin_id: string | null;
  action: string;
  reason: string | null;
  ip: string | null;
  occurred_at: string;
}

export interface ApiSubscription {
  id: string;
  tenant_id: string;
  plan_code: string;
  plan_name: string;
  plan_version: number;
  collection: 'stripe' | 'offline';
  status: string;
  interval: 'month' | 'year';
  currency: string;
  custom_amount: number | null;
  quantity: number;
  monthly_amount: number;
  trial_ends_at: string | null;
  current_period_end: string | null;
  cancel_at: string | null;
  pause_until: string | null;
  coupon_code: string | null;
  stripe_subscription_id: string | null;
  created_at: string | null;
}

export interface ApiOverride {
  key: string;
  value: unknown;
  reason: string;
  set_by: string | null;
  expires_at: string | null;
  active: boolean;
}

const orUndef = <T>(v: T | null | undefined): T | undefined => (v === null || v === undefined ? undefined : v);

export interface TenantRow {
  id: string;
  name: string;
  slug: string;
  status: LifecycleStatus | string;
  country?: string;
  ownerName?: string;
  ownerEmail?: string;
  planCode?: string;
  planName?: string;
  companiesCount: number;
  usersCount: number;
  createdAt?: string;
  lastActivityAt?: string;
  trialEndsAt?: string;
}

export function toTenantRow(t: ApiTenant): TenantRow {
  return {
    id: t.id,
    name: t.name,
    slug: t.slug,
    status: t.status,
    country: orUndef(t.country),
    ownerName: orUndef(t.owner?.name),
    ownerEmail: orUndef(t.owner?.email),
    planCode: orUndef(t.plan?.code),
    planName: orUndef(t.plan?.name),
    companiesCount: t.companies_count,
    usersCount: t.users_count,
    createdAt: orUndef(t.created_at),
    lastActivityAt: orUndef(t.last_activity_at),
    trialEndsAt: orUndef(t.trial_ends_at),
  };
}

export interface LimitUsage {
  metric: string;
  used: number;
  limit: number | null;
}
export interface TimelineEvent {
  id: string;
  from: string;
  to: string;
  reason: string;
  actorType: string;
  occurredAt: string;
}
export interface TenantNote {
  id: string;
  body: string;
  pinned: boolean;
  authorName?: string;
  createdAt: string;
}
export interface TenantDetail extends TenantRow {
  limits: LimitUsage[];
  timeline: TimelineEvent[];
  notes: TenantNote[];
}

export function toNote(n: ApiNote): TenantNote {
  return { id: n.id, body: n.body, pinned: n.pinned, authorName: orUndef(n.admin.name), createdAt: n.created_at };
}

export function toTenantDetail(d: ApiTenantDetail): TenantDetail {
  return {
    ...toTenantRow(d),
    limits: Object.entries(d.limits_usage ?? {}).map(([metric, v]) => ({ metric, used: v.used, limit: v.limit })),
    timeline: (d.lifecycle_timeline ?? []).map((e) => ({ id: e.id, from: e.from, to: e.to, reason: e.reason, actorType: e.actor_type, occurredAt: e.occurred_at })),
    notes: (d.notes ?? []).map(toNote),
  };
}

export interface TenantUser {
  id: string;
  name: string;
  email: string;
  orgRole: string;
  status: string;
  twoFactor: boolean;
  lastLoginAt?: string;
}
export const toTenantUser = (u: ApiTenantUser): TenantUser => ({
  id: u.id, name: u.name, email: u.email, orgRole: u.org_role, status: u.status, twoFactor: u.two_factor, lastLoginAt: orUndef(u.last_login_at),
});

export interface TenantCompany {
  id: string;
  name: string;
  status: string;
  baseCurrency: string;
  country?: string;
  fiscalYearStart?: string;
  openPeriods: number;
  closedPeriods: number;
  postedJournals: number;
}
export const toTenantCompany = (c: ApiTenantCompany): TenantCompany => ({
  id: c.id, name: c.name, status: c.status, baseCurrency: c.base_currency, country: orUndef(c.country), fiscalYearStart: orUndef(c.fiscal_year_start),
  openPeriods: c.open_periods, closedPeriods: c.closed_periods, postedJournals: c.posted_journals,
});

export interface AuditEvent {
  id: string;
  action: string;
  reason?: string;
  ip?: string;
  occurredAt: string;
}
export const toAuditEvent = (e: ApiAuditEvent): AuditEvent => ({ id: e.id, action: e.action, reason: orUndef(e.reason), ip: orUndef(e.ip), occurredAt: e.occurred_at });

export interface Subscription {
  id: string;
  planCode: string;
  planName: string;
  planVersion: number;
  collection: 'stripe' | 'offline';
  status: string;
  interval: 'month' | 'year';
  currency: string;
  /** Integer minor units of `currency`. */
  customAmount?: number;
  quantity: number;
  /** Integer minor units of `currency`. */
  monthlyAmount: number;
  trialEndsAt?: string;
  currentPeriodEnd?: string;
  cancelAt?: string;
  pauseUntil?: string;
  couponCode?: string;
  stripeSubscriptionId?: string;
}

export function toSubscription(s: ApiSubscription | null): Subscription | null {
  if (!s) return null;
  return {
    id: s.id,
    planCode: s.plan_code,
    planName: s.plan_name,
    planVersion: s.plan_version,
    collection: s.collection,
    status: s.status,
    interval: s.interval,
    currency: s.currency,
    customAmount: orUndef(s.custom_amount),
    quantity: s.quantity,
    monthlyAmount: s.monthly_amount,
    trialEndsAt: orUndef(s.trial_ends_at),
    currentPeriodEnd: orUndef(s.current_period_end),
    cancelAt: orUndef(s.cancel_at),
    pauseUntil: orUndef(s.pause_until),
    couponCode: orUndef(s.coupon_code),
    stripeSubscriptionId: orUndef(s.stripe_subscription_id),
  };
}

export interface Override {
  key: string;
  value: unknown;
  reason: string;
  expiresAt?: string;
  active: boolean;
}
export const toOverride = (o: ApiOverride): Override => ({ key: o.key, value: o.value, reason: o.reason, expiresAt: orUndef(o.expires_at), active: o.active });

export interface PlanOption {
  code: string;
  name: string;
}
