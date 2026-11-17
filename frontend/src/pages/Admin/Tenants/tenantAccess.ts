/** What a staff member's abilities (from `GET /api/admin/session`) open on a tenant page. Pure. */
import { canDo } from '@/api/adapters/platform/session';

export type TenantTabId = 'overview' | 'subscription' | 'users' | 'companies' | 'overrides' | 'notes' | 'audit';

export interface TenantTab {
  id: TenantTabId;
  label: string;
  /** The ability the API route behind the tab needs. */
  ability: string;
}

const TABS: TenantTab[] = [
  { id: 'overview', label: 'Overview', ability: 'tenants.view' },
  { id: 'subscription', label: 'Subscription', ability: 'billing.view' },
  { id: 'users', label: 'Users', ability: 'tenants.view' },
  { id: 'companies', label: 'Companies', ability: 'tenants.view' },
  { id: 'overrides', label: 'Overrides', ability: 'billing.view' },
  { id: 'notes', label: 'Notes', ability: 'tenants.notes' },
  { id: 'audit', label: 'Audit', ability: 'audit.view' },
];

/** Without `tenants.view` the API refuses every tenant read, so no tab is offered at all. */
export function visibleTenantTabs(can: string[]): TenantTab[] {
  if (!canDo({ can }, 'tenants.view')) return [];
  return TABS.filter((t) => canDo({ can }, t.ability));
}

export interface TenantActions {
  lifecycle: boolean;
  userSupport: boolean;
  impersonate: boolean;
  deletion: boolean;
  manageBilling: boolean;
  overrides: boolean;
  notes: boolean;
  resendInvitation: boolean;
}

export function tenantActionsFor(can: string[]): TenantActions {
  const has = (a: string) => canDo({ can }, a);
  return {
    lifecycle: has('tenants.lifecycle'),
    userSupport: has('tenants.lifecycle'),
    impersonate: has('impersonate.read'),
    deletion: has('compliance.manage'),
    manageBilling: has('billing.manage'),
    overrides: has('billing.manage'),
    notes: has('tenants.notes'),
    resendInvitation: has('tenants.provision'),
  };
}
