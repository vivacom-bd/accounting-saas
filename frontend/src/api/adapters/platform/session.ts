/**
 * Admin session -> what the console needs: who is signed in, what they may open, and where the route guard
 * sends them when the API says no. Pure; no React, no network. Abilities come from the API (AdminPermissions).
 */
import type { AdminRole } from '@/types/admin';
import { ApiError } from '@/api/errors';
import { initials } from '@/api/adapters/session';

export type ApiAdminSession = {
  id: string;
  name: string;
  email: string;
  role: AdminRole;
  can: string[];
};

export interface AdminSession {
  id: string;
  name: string;
  email: string;
  role: AdminRole;
  roleTitle: string;
  initials: string;
  can: string[];
}

const ROLE_TITLES: Record<AdminRole, string> = {
  owner: 'Owner',
  finance: 'Finance',
  sales: 'Sales',
  support: 'Support',
  engineer: 'Engineer',
  readonly: 'Read-only',
};

export function toAdminSession(s: ApiAdminSession): AdminSession {
  return { id: s.id, name: s.name, email: s.email, role: s.role, roleTitle: ROLE_TITLES[s.role] ?? s.role, initials: initials(s.name), can: s.can ?? [] };
}

/** No ability required (undefined) means everyone signed in may see it. */
export function canDo(session: Pick<AdminSession, 'can'>, ability: string | undefined): boolean {
  return ability === undefined || session.can.includes(ability);
}

export interface AdminNavItem {
  label: string;
  to: string;
  exact?: boolean;
  /** The ability that opens this area (AdminPermissions); undefined = any signed-in staff member. */
  ability?: string;
  /** Not built against the API yet: shown in mock mode only (hidden in live mode, nav and route). */
  mockOnly?: boolean;
  /** Has no mock page: shown in live mode only. */
  liveOnly?: boolean;
}
export interface AdminNavSection {
  title: string;
  items: AdminNavItem[];
}

export const ADMIN_NAV: AdminNavSection[] = [
  {
    title: 'Platform',
    items: [
      { label: 'Overview', to: '/admin', exact: true, ability: 'billing.view' },
      { label: 'Tenants', to: '/admin/tenants', ability: 'tenants.view' },
    ],
  },
  {
    title: 'Commercial',
    items: [
      { label: 'Plans and pricing', to: '/admin/plans', ability: 'plans.view' },
      { label: 'Coupons and discounts', to: '/admin/coupons', ability: 'plans.view' },
      { label: 'Billing operations', to: '/admin/billing', ability: 'billing.view' },
      { label: 'Trials and growth', to: '/admin/growth', ability: 'growth.manage' },
      { label: 'Partners and resellers', to: '/admin/partners', mockOnly: true },
    ],
  },
  {
    title: 'Operations',
    items: [
      { label: 'Support and communications', to: '/admin/support', ability: 'tenants.view' },
      { label: 'Platform telemetry', to: '/admin/ops', ability: 'ops.view' },
      { label: 'Feature flags', to: '/admin/flags', ability: 'ops.view' },
    ],
  },
  {
    title: 'Governance',
    items: [
      { label: 'Staff and security', to: '/admin/staff', ability: 'staff.manage' },
      { label: 'Audit log', to: '/admin/audit', ability: 'audit.view', liveOnly: true },
      { label: 'Compliance and GDPR', to: '/admin/compliance', ability: 'compliance.manage' },
    ],
  },
];

/** Live navigation: only what these abilities open, without sections left empty and without mock-only pages. */
export function visibleAdminNav(can: string[], nav: AdminNavSection[] = ADMIN_NAV): AdminNavSection[] {
  return nav
    .map((section) => ({ ...section, items: section.items.filter((i) => !i.mockOnly && canDo({ can }, i.ability)) }))
    .filter((section) => section.items.length > 0);
}

export type GuardTarget = { kind: 'allow' } | { kind: 'redirect'; to: string; next?: string } | { kind: 'error' };

/**
 * What the route guard does with the outcome of `GET /api/admin/session`: a password-only sign-in goes to the
 * code page, an idle or missing session to sign-in with the way back, a blocked network to its own page.
 */
export function adminGuardTarget(error: unknown, from: { pathname: string; search?: string }): GuardTarget {
  if (!error) return { kind: 'allow' };
  if (error instanceof ApiError) {
    if (error.status === 401 && error.code === 'admin_two_factor_required') return { kind: 'redirect', to: '/admin/two-factor' };
    if (error.status === 401) return { kind: 'redirect', to: '/admin/login', next: `${from.pathname}${from.search ?? ''}` };
    if (error.status === 403 && error.code === 'admin_ip_blocked') return { kind: 'redirect', to: '/admin/blocked' };
  }
  return { kind: 'error' };
}
