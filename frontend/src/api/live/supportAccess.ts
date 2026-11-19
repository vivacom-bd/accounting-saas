/** Consented platform support access (plan Task 14, G4): owners/admins open or close the grant by which support may impersonate. */
import { http } from '../http';

export interface SupportGrant {
  id: string;
  hours: number;
  expires_at: string;
  granted_by: string;
  created_at: string;
}
export interface SupportAccess {
  open: boolean;
  grant: SupportGrant | null;
}

export async function fetchSupportAccess(): Promise<SupportAccess> {
  const res = await http.send<{ data: SupportAccess }>('GET', '/v1/org/support-access');
  return res.data;
}

export async function grantSupportAccess(hours: 24 | 72 | 168): Promise<SupportAccess> {
  const res = await http.send<{ data: SupportAccess }>('POST', '/v1/org/support-access', { hours });
  return res.data;
}

export async function revokeSupportAccess(): Promise<SupportAccess> {
  const res = await http.send<{ data: SupportAccess }>('POST', '/v1/org/support-access/revoke');
  return res.data;
}
