import React from 'react';
import { describe, it, expect } from 'vitest';
import { renderToStaticMarkup } from 'react-dom/server';
import { VersionCard, VersionFormView } from '@/pages/Admin/Plans/PlansViews';
import { emptyVersionForm, type ApiPlanVersion } from '@/api/adapters/platform/catalogue';

const noop = () => {};
const version = (over: Partial<ApiPlanVersion> = {}): ApiPlanVersion => ({
  id: 'v1', plan_id: 'p1', version: 3, effective_from: '2026-01-01T00:00:00+00:00', trial_days: 14, stripe_product_id: null,
  prices: { USD: { monthly: 4900, annual: 49000 }, KES: { monthly: 650000, annual: null } },
  limits: { companies: null, users: 10, api_clients: 2, journal_lines_per_month: 1000, storage_mb: 500, api_calls_per_day: 100 },
  entitlements: { multi_currency: true, budgets: false }, ...over,
});

describe('version card', () => {
  it('an effective version is read-only: figures, no inputs, no edit control', () => {
    const html = renderToStaticMarkup(<VersionCard version={version()} effective />);
    expect(html).toMatch(/Read-only/i);
    expect(html).not.toContain('<input');
    expect(html).not.toMatch(/Edit/);
    expect(html).toContain('$49.00');
    expect(html).toContain('KSh 6,500.00');
    expect(html).toContain('Custom quote');
    expect(html).toMatch(/Unlimited/);
  });
  it('a scheduled version says when it starts', () => {
    const html = renderToStaticMarkup(<VersionCard version={version({ effective_from: '2026-12-01T00:00:00+00:00' })} effective={false} />);
    expect(html).toMatch(/Scheduled/i);
    expect(html).not.toMatch(/Read-only/i);
  });
});

describe('new version form', () => {
  const render = (errors: Record<string, string> = {}) =>
    renderToStaticMarkup(<VersionFormView form={emptyVersionForm('2026-11-18')} errors={errors} onChange={noop} onSubmit={noop} onCancel={noop} busy={false} today="2026-11-18" />);

  it('has a monthly and an annual money input for each of USD, KES and EUR', () => {
    const html = render();
    for (const c of ['USD', 'KES', 'EUR']) for (const i of ['monthly', 'annual']) expect(html).toContain(`id="price-${c}-${i}"`);
  });
  it('has an unlimited switch per limit and a toggle per entitlement', () => {
    const html = render();
    expect(html).toContain('id="limit-users-unlimited"');
    expect(html).toContain('id="ent-consolidation"');
  });
  it('the effective date cannot be picked before today', () => {
    expect(render()).toContain('min="2026-11-18"');
  });
  it('shows a field error in words', () => {
    expect(render({ 'prices.KES.monthly': 'Enter an amount.' })).toContain('Enter an amount.');
  });
});
