import React from 'react';
import { describe, it, expect } from 'vitest';
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { ProvisionFields, ProvisionResult } from '@/pages/Admin/Tenants/ProvisionTenantDialog';
import { LifecycleActionForm } from '@/pages/Admin/Tenants/LifecycleActionDialog';
import { SubscriptionPanelView } from '@/pages/Admin/Tenants/SubscriptionPanel';
import { ImpersonateFields } from '@/pages/Admin/Tenants/ImpersonateDialog';
import { TenantFilterBar, TenantsTable } from '@/pages/Admin/Tenants/LiveTenantsList';
import { TenantTabsNav, LimitsUsage } from '@/pages/Admin/Tenants/LiveTenantDetail';
import { emptyProvisionForm, emptyImpersonationForm } from '@/api/adapters/platform/tenantForms';
import type { Subscription, TenantRow } from '@/api/adapters/platform/tenants';

const render = (el: React.ReactElement) => renderToStaticMarkup(<MemoryRouter>{el}</MemoryRouter>);
const noop = () => {};

const row: TenantRow = {
  id: 't1', name: 'Acme Ltd', slug: 'acme', status: 'trial', country: 'KE', ownerName: 'Ann', ownerEmail: 'ann@acme.test', planCode: 'pro', planName: 'Professional',
  companiesCount: 2, usersCount: 5, createdAt: '2026-11-01T00:00:00+00:00', lastActivityAt: undefined, trialEndsAt: undefined,
};

describe('tenants list', () => {
  it('filter bar shows the current filters and the lifecycle statuses the API accepts', () => {
    const html = render(<TenantFilterBar filters={{ status: 'trial', q: 'acme', plan: '', country: '', sort: 'created_at' }} plans={[{ code: 'pro', name: 'Professional' }]} onChange={noop} />);
    expect(html).toContain('value="acme"');
    for (const s of ['trial', 'active', 'past_due', 'read_only', 'suspended', 'cancelled']) expect(html).toContain(`value="${s}"`);
    expect(html).toContain('Professional');
  });

  it('table lists a row, hides health score and MRR, and offers Load more only when there is a next cursor', () => {
    const more = render(<TenantsTable rows={[row]} hasMore loadingMore={false} onLoadMore={noop} onOpen={noop} />);
    expect(more).toContain('Acme Ltd');
    expect(more).toContain('ann@acme.test');
    expect(more).toContain('Load more');
    expect(more).not.toMatch(/health/i);
    expect(more).not.toMatch(/MRR/);
    const done = render(<TenantsTable rows={[row]} hasMore={false} loadingMore={false} onLoadMore={noop} onOpen={noop} />);
    expect(done).not.toContain('Load more');
  });

  it('says so when nothing matches', () => {
    expect(render(<TenantsTable rows={[]} hasMore={false} loadingMore={false} onLoadMore={noop} onOpen={noop} />)).toMatch(/No tenants/);
  });
});

describe('tenant page', () => {
  it('the tab strip only holds the tabs the abilities open', () => {
    const view = render(<TenantTabsNav can={['tenants.view']} active="overview" onSelect={noop} />);
    expect(view).toContain('Overview');
    expect(view).toContain('Users');
    expect(view).not.toContain('Subscription');
    expect(view).not.toContain('Audit');
    const full = render(<TenantTabsNav can={['tenants.view', 'billing.view', 'audit.view', 'tenants.notes']} active="overview" onSelect={noop} />);
    for (const t of ['Subscription', 'Overrides', 'Notes', 'Audit']) expect(full).toContain(t);
  });

  it('limits against usage: shows used of limit, or unlimited when the plan sets none', () => {
    const html = render(<LimitsUsage limits={[{ metric: 'companies', used: 2, limit: 3 }, { metric: 'users', used: 5, limit: null }]} />);
    expect(html).toContain('2');
    expect(html).toContain('3');
    expect(html).toMatch(/Unlimited/);
  });
});

describe('provision dialog', () => {
  it('shows a field error under each invalid field and a trial or offline section', () => {
    const html = render(<ProvisionFields form={emptyProvisionForm()} errors={{ ownerEmail: 'Enter a valid email address.' }} plans={[]} onChange={noop} />);
    expect(html).toContain('Enter a valid email address.');
    expect(html).toMatch(/Trial days/);
    const offline = render(<ProvisionFields form={{ ...emptyProvisionForm(), billing: 'offline' }} errors={{}} plans={[]} onChange={noop} />);
    expect(offline).toMatch(/Currency/);
    expect(offline).toMatch(/invoiced offline/i);
  });

  it('after provisioning it shows the new tenant and who the owner invitation went to', () => {
    const html = render(<ProvisionResult tenant={row} ownerInvitation={{ email: 'ann@acme.test', expiresAt: '2026-11-25T00:00:00+00:00' }} onOpen={noop} onClose={noop} />);
    expect(html).toContain('Acme Ltd');
    expect(html).toMatch(/Invitation sent to/);
    expect(html).toContain('ann@acme.test');
  });
});

describe('lifecycle dialog', () => {
  it('lists only the moves the backend allows from the current state', () => {
    const html = render(<LifecycleActionForm from="trial" to="" reason="" error={null} busy={false} canRequestDeletion onTo={noop} onReason={noop} onSubmit={noop} onRequestDeletion={noop} />);
    expect(html).toContain('value="active"');
    expect(html).toContain('value="read_only"');
    expect(html).not.toContain('value="suspended"');
    expect(html).not.toContain('value="cancelled"');
    expect(html).not.toContain('value="deleted"');
  });

  it('cancelling goes through a deletion request, offered only to staff who may', () => {
    const withIt = render(<LifecycleActionForm from="active" to="" reason="" error={null} busy={false} canRequestDeletion onTo={noop} onReason={noop} onSubmit={noop} onRequestDeletion={noop} />);
    expect(withIt).toContain('Request deletion');
    const without = render(<LifecycleActionForm from="active" to="" reason="" error={null} busy={false} canRequestDeletion={false} onTo={noop} onReason={noop} onSubmit={noop} onRequestDeletion={noop} />);
    expect(without).not.toContain('Request deletion');
  });

  it('a deleted tenant has nothing to move to', () => {
    expect(render(<LifecycleActionForm from="deleted" to="" reason="" error={null} busy={false} canRequestDeletion={false} onTo={noop} onReason={noop} onSubmit={noop} onRequestDeletion={noop} />)).toMatch(/No further changes/);
  });
});

describe('subscription panel', () => {
  const sub: Subscription = {
    id: 's1', planCode: 'pro', planName: 'Professional', planVersion: 1, collection: 'offline', status: 'active', interval: 'month', currency: 'KES',
    customAmount: undefined, quantity: 1, monthlyAmount: 499900, trialEndsAt: undefined, currentPeriodEnd: '2026-12-01T00:00:00+00:00', cancelAt: undefined, pauseUntil: undefined,
    couponCode: undefined, stripeSubscriptionId: undefined,
  };
  const base = { plans: [{ code: 'pro', name: 'Professional' }], canManage: true, busy: false, error: null, onAction: noop, onSubscribe: noop };

  it('shows the Stripe not connected banner and disables the Stripe option while offline collection stays available', () => {
    const html = render(<SubscriptionPanelView {...base} subscription={null} stripeConfigured={false} />);
    expect(html).toContain('Stripe not connected');
    expect(html).toMatch(/<option[^>]*value="stripe"[^>]*disabled/);
    expect(html).toMatch(/<option[^>]*value="offline"(?![^>]*disabled)/);
  });

  it('shows no banner when Stripe is connected', () => {
    const html = render(<SubscriptionPanelView {...base} subscription={null} stripeConfigured />);
    expect(html).not.toContain('Stripe not connected');
    expect(html).not.toMatch(/<option[^>]*value="stripe"[^>]*disabled/);
  });

  it('shows the current plan with the monthly amount in the currency exponent', () => {
    const html = render(<SubscriptionPanelView {...base} subscription={sub} stripeConfigured />);
    expect(html).toContain('Professional');
    expect(html).toContain('4,999.00');
  });

  it('hides the change buttons from staff who cannot manage billing', () => {
    const html = render(<SubscriptionPanelView {...base} canManage={false} subscription={sub} stripeConfigured />);
    expect(html).toContain('Professional');
    expect(html).not.toMatch(/Pause|Cancel at period end|Change plan/);
  });
});

describe('impersonate dialog', () => {
  const users = [{ id: 'u1', name: 'Ann', email: 'ann@acme.test' }];

  it('read mode asks for a reason and no approval id', () => {
    const html = render(<ImpersonateFields form={emptyImpersonationForm()} errors={{ reason: 'Give a reason.' }} users={users} canWrite isOwner={false} onChange={noop} />);
    expect(html).toContain('Give a reason.');
    expect(html).not.toMatch(/Approval id/);
  });

  it('write mode asks for the approval id', () => {
    const html = render(<ImpersonateFields form={{ ...emptyImpersonationForm(), mode: 'write' }} errors={{ approvalId: 'Write mode needs an approved request.' }} users={users} canWrite isOwner={false} onChange={noop} />);
    expect(html).toMatch(/Approval id/);
    expect(html).toContain('Write mode needs an approved request.');
  });

  it('offers write mode and the owner override only to those who may use them', () => {
    const readOnly = render(<ImpersonateFields form={emptyImpersonationForm()} errors={{}} users={users} canWrite={false} isOwner={false} onChange={noop} />);
    expect(readOnly).not.toMatch(/Write/);
    expect(readOnly).not.toMatch(/override/i);
    const owner = render(<ImpersonateFields form={emptyImpersonationForm()} errors={{}} users={users} canWrite isOwner onChange={noop} />);
    expect(owner).toMatch(/override/i);
  });
});
