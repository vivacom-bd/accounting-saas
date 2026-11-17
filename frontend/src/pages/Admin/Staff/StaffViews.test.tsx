import React from 'react';
import { describe, it, expect } from 'vitest';
import { renderToStaticMarkup } from 'react-dom/server';
import { ApprovalRow, VerifyResult, StaffProblem } from '@/pages/Admin/Staff/StaffViews';
import type { ApiApproval } from '@/api/adapters/platform/staff';
import { ApiError } from '@/api/errors';

const noop = () => {};
const approval = (over: Partial<ApiApproval> = {}): ApiApproval => ({
  id: 'ap1', kind: 'refund', tenant_id: 't1', subject: { amount: 5000, currency: 'USD' }, reason: 'duplicate charge', requested_by: 'other',
  approved_by: null, status: 'pending', rejection_reason: null, expires_at: '2026-11-20T00:00:00+00:00', decided_at: null, executed_at: null, created_at: '2026-11-18T00:00:00+00:00', ...over,
});
const row = (a: ApiApproval, meId = 'me', can = ['approvals.decide']) =>
  renderToStaticMarkup(<ApprovalRow approval={a} meId={meId} can={can} names={{ other: 'Wanjiru', me: 'Me' }} onApprove={noop} onReject={noop} busy={false} />);

describe('approvals queue row', () => {
  it('offers approve and reject on someone else\'s pending request', () => {
    const html = row(approval());
    expect(html).toContain('Approve');
    expect(html).toContain('Reject');
    expect(html).toContain('Wanjiru');
  });
  it('hides approve and reject on my own request and says why', () => {
    const html = row(approval({ requested_by: 'me' }));
    expect(html).not.toMatch(/>Approve</);
    expect(html).not.toMatch(/>Reject</);
    expect(html).toMatch(/another approver|cannot decide your own/i);
  });
  it('hides them once decided', () => {
    expect(row(approval({ status: 'approved' }))).not.toMatch(/>Approve</);
  });
});

describe('audit verify result', () => {
  it('shows intact', () => {
    const html = renderToStaticMarkup(<VerifyResult report={{ intact: true, events: 42, broken_id: null }} />);
    expect(html).toContain('Chain intact: 42 events checked.');
  });
  it('shows the broken event id', () => {
    const html = renderToStaticMarkup(<VerifyResult report={{ intact: false, events: 42, broken_id: 7 }} />);
    expect(html).toContain('Chain broken at event 7.');
    expect(html).toContain('role="alert"');
  });
});

describe('staff problems', () => {
  it('surfaces last_staff_owner in words', () => {
    const e = new ApiError({ status: 409, code: 'last_staff_owner', detail: 'x', requestId: null });
    expect(renderToStaticMarkup(<StaffProblem error={e} />)).toMatch(/at least one active owner/i);
  });
  it('renders nothing without an error', () => {
    expect(renderToStaticMarkup(<StaffProblem error={null} />)).toBe('');
  });
});
