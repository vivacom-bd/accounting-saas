/** Live admin session calls (plan Task 20): sign-in, two-factor, set password, session, sign-out. */
import { adminHttp } from '../../adminHttp';
import { toAdminSession, type AdminSession, type ApiAdminSession } from '../../adapters/platform/session';
import type { Enrolment } from '../../adapters/platform/login';
import { createIntent, type Intent } from '../../idempotency';

export async function getAdminSession(): Promise<AdminSession> {
  const res = (await adminHttp.get('/session')) as { data: ApiAdminSession };
  return toAdminSession(res.data);
}

export async function adminLogin(email: string, password: string, intent: Intent = createIntent()): Promise<'challenge' | 'enrol'> {
  const res = await adminHttp.post('/auth/login', { body: { email, password }, intent });
  return res.two_factor;
}

export async function startEnrolment(): Promise<Enrolment> {
  return (await adminHttp.post('/auth/two-factor/enrol')) as Enrolment;
}

/** Confirms the first code; the response is the only time the recovery codes are ever returned. */
export async function confirmEnrolment(code: string, intent: Intent = createIntent()): Promise<string[]> {
  const res = (await adminHttp.post('/auth/two-factor/confirm', { body: { code }, intent })) as { recovery_codes: string[] };
  return res.recovery_codes;
}

export async function verifyTwoFactor(input: { code?: string; recoveryCode?: string }, intent: Intent = createIntent()): Promise<AdminSession> {
  const body = input.recoveryCode ? { recovery_code: input.recoveryCode } : { code: input.code ?? '' };
  const res = (await adminHttp.post('/auth/two-factor', { body, intent })) as { data: ApiAdminSession };
  return toAdminSession(res.data);
}

export async function adminLogout(): Promise<void> {
  await adminHttp.post('/auth/logout');
}

export async function setAdminPassword(token: string, password: string, intent: Intent = createIntent()): Promise<void> {
  await adminHttp.post('/auth/set-password', { body: { token, password }, intent });
}
