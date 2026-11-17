/** Live staff, approvals and admin-audit calls (plan Task 22). Casts to the hand-written shapes sit here, once per call. */
import { adminHttp } from '../../adminHttp';
import { createIntent, type Intent } from '../../idempotency';
import type { AdminRole } from '@/types/admin';
import type { ApiApproval, ApiAuditPage, ApiStaff, ApiVerifyReport, ApprovalStatus } from '../../adapters/platform/staff';

type Data<T> = { data: T };
const call = <T>(method: string, path: string, body?: unknown, intent?: Intent) => adminHttp.send<Data<T>>(method, path, body, intent).then((r) => r.data);
const qs = (q: Record<string, string>) => {
  const s = new URLSearchParams(q).toString();
  return s === '' ? '' : `?${s}`;
};

export const listStaff = () => call<ApiStaff[]>('GET', '/staff');
export const inviteStaff = (body: { email: string; name: string; role: AdminRole }, intent: Intent = createIntent()) => call<ApiStaff>('POST', '/staff', body, intent);
export const updateStaff = (id: string, body: { role?: AdminRole; name?: string; ip_allowlist?: string[] }, intent: Intent = createIntent()) =>
  call<ApiStaff>('PATCH', `/staff/${encodeURIComponent(id)}`, body, intent);
export const deactivateStaff = (id: string, intent: Intent = createIntent()) => call<ApiStaff>('POST', `/staff/${encodeURIComponent(id)}/deactivate`, undefined, intent);
export const resetStaffTwoFactor = (id: string, intent: Intent = createIntent()) => call<ApiStaff>('POST', `/staff/${encodeURIComponent(id)}/reset-two-factor`, undefined, intent);

export const listApprovals = (status?: ApprovalStatus) => call<ApiApproval[]>('GET', `/approvals${qs(status ? { status } : {})}`);
export const approveApproval = (id: string, intent: Intent = createIntent()) => call<ApiApproval>('POST', `/approvals/${encodeURIComponent(id)}/approve`, undefined, intent);
export const rejectApproval = (id: string, reason: string, intent: Intent = createIntent()) =>
  call<ApiApproval>('POST', `/approvals/${encodeURIComponent(id)}/reject`, { reason }, intent);

export const listAudit = (query: Record<string, string>) => adminHttp.send<ApiAuditPage>('GET', `/audit${qs(query)}`);
export const verifyAuditChain = (intent: Intent = createIntent()) => call<ApiVerifyReport>('POST', '/audit/verify', undefined, intent);
