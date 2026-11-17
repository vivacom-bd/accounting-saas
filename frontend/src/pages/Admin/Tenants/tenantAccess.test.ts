import { describe, it, expect } from 'vitest';
import { visibleTenantTabs, tenantActionsFor } from '@/pages/Admin/Tenants/tenantAccess';

const tabs = (can: string[]) => visibleTenantTabs(can).map((t) => t.id);

describe('what a staff member sees on a tenant page', () => {
  it('a read-only viewer sees overview, users and companies, and none of the billing, notes or audit tabs', () => {
    expect(tabs(['tenants.view'])).toEqual(['overview', 'users', 'companies']);
  });
  it('billing.view adds subscription and overrides; tenants.notes adds notes; audit.view adds audit', () => {
    expect(tabs(['tenants.view', 'billing.view', 'tenants.notes', 'audit.view'])).toEqual(['overview', 'subscription', 'users', 'companies', 'overrides', 'notes', 'audit']);
  });
  it('shows nothing without tenants.view', () => {
    expect(tabs(['billing.view'])).toEqual([]);
  });
  it('actions follow the abilities', () => {
    expect(tenantActionsFor(['tenants.view'])).toEqual({ lifecycle: false, userSupport: false, impersonate: false, deletion: false, manageBilling: false, overrides: false, notes: false, resendInvitation: false });
    const all = tenantActionsFor(['tenants.view', 'tenants.lifecycle', 'impersonate.read', 'compliance.manage', 'billing.manage', 'tenants.notes', 'tenants.provision']);
    expect(all).toMatchObject({ lifecycle: true, userSupport: true, impersonate: true, deletion: true, manageBilling: true, overrides: true, notes: true, resendInvitation: true });
  });
});
