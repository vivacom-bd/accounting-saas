import { describe, it, expect } from 'vitest';
import {
  parseAllowlist, validateAllowEntry, canDecideApproval, verifyOutcome, staffProblemMessage, approvalHeadline, auditQuery, type ApiApproval,
} from '@/api/adapters/platform/staff';
import { ApiError } from '@/api/errors';

describe('IP allowlist validation', () => {
  it('accepts IPv4, IPv6 and CIDR blocks', () => {
    for (const ok of ['203.0.113.7', '10.0.0.0/8', '2001:db8::1', '2001:db8::/32', '::1', '192.168.1.0/24']) expect(validateAllowEntry(ok)).toBeNull();
  });
  it('refuses malformed addresses and prefixes out of range', () => {
    for (const bad of ['', 'hello', '256.1.1.1', '1.2.3', '10.0.0.0/33', '10.0.0.0/', '2001:db8::/129', '1.2.3.4/-1', '1.2.3.4/8/8', '12345::1', '1.2.3.04x']) expect(validateAllowEntry(bad)).not.toBeNull();
  });
  it('parses one entry per line or comma, trims, drops blanks and reports each bad line', () => {
    expect(parseAllowlist('10.0.0.1\n 10.0.1.0/24, 2001:db8::1 \n\n')).toEqual({ ok: true, entries: ['10.0.0.1', '10.0.1.0/24', '2001:db8::1'] });
    const r = parseAllowlist('10.0.0.1\nnope\n300.0.0.1');
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.errors.map((e) => e.entry)).toEqual(['nope', '300.0.0.1']);
  });
  it('allows an empty list (no restriction) and caps it at 50 entries', () => {
    expect(parseAllowlist('')).toEqual({ ok: true, entries: [] });
    const many = Array.from({ length: 51 }, (_, i) => `10.0.0.${i}`).join('\n');
    expect(parseAllowlist(many).ok).toBe(false);
  });
});

describe('approvals', () => {
  const a = (over: Partial<ApiApproval> = {}): ApiApproval => ({
    id: 'ap1', kind: 'refund', tenant_id: 't1', subject: { amount: 5000, currency: 'KES' }, reason: 'duplicate charge', requested_by: 'me',
    approved_by: null, status: 'pending', rejection_reason: null, expires_at: '2026-11-20T00:00:00+00:00', decided_at: null, executed_at: null, created_at: '2026-11-18T00:00:00+00:00', ...over,
  });
  it('hides approve and reject on my own request, and on anything no longer pending', () => {
    expect(canDecideApproval(a({ requested_by: 'me' }), 'me', ['approvals.decide'])).toBe(false);
    expect(canDecideApproval(a({ requested_by: 'other' }), 'me', ['approvals.decide'])).toBe(true);
    expect(canDecideApproval(a({ requested_by: 'other', status: 'approved' }), 'me', ['approvals.decide'])).toBe(false);
  });
  it('hides the buttons without the ability', () => {
    expect(canDecideApproval(a({ requested_by: 'other' }), 'me', ['staff.manage'])).toBe(false);
  });
  it('writes a headline from the kind and, for a refund, the minor-unit amount in its currency', () => {
    expect(approvalHeadline(a())).toBe('Refund KSh 50.00');
    expect(approvalHeadline(a({ kind: 'tenant_delete', subject: {} }))).toBe('Tenant deletion');
  });
});

describe('audit verify', () => {
  it('reports an intact chain with its event count', () => {
    expect(verifyOutcome({ intact: true, events: 120, broken_id: null })).toEqual({ tone: 'ok', text: 'Chain intact: 120 events checked.' });
  });
  it('names the first broken event', () => {
    expect(verifyOutcome({ intact: false, events: 120, broken_id: 57 })).toEqual({ tone: 'broken', text: 'Chain broken at event 57.' });
  });
  it('builds the filter query and drops empty filters', () => {
    expect(auditQuery({ admin: '', tenant: 't', action: 'x.y', from: '2026-01-01', to: '' }, 'c1')).toEqual({ tenant: 't', action: 'x.y', from: '2026-01-01', cursor: 'c1' });
    expect(auditQuery({ admin: '', tenant: '', action: '', from: '', to: '' })).toEqual({});
  });
});

describe('staff problems', () => {
  const e = (status: number, code: string) => new ApiError({ status, code, detail: 'server text', requestId: null });
  it('explains the last owner refusal', () => {
    expect(staffProblemMessage(e(409, 'last_staff_owner'))).toMatch(/at least one active owner/i);
  });
  it('explains the self lockout refusal', () => {
    expect(staffProblemMessage(e(422, 'admin_ip_self_lockout'))).toMatch(/lock you out/i);
  });
  it('falls back to the shared error text', () => {
    expect(staffProblemMessage(e(403, 'forbidden'))).toMatch(/permission/i);
  });
});
