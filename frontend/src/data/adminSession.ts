/**
 * Admin session seam. Live: `GET /api/admin/session` through React Query (an error here is the route guard's
 * input, so it is kept as the query error and never retried). Mock: no query; the zustand admin store stays the source.
 */
import { useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { IS_LIVE } from '@/api/config';
import { getAdminSession } from '@/api/live/platform/session';
import { setAdminUnauthenticatedListener } from '@/api/adminHttp';
import { queryClient } from '@/api/queryClient';

export const adminSessionKey = ['admin', 'session'] as const;

export function useAdminSession() {
  return useQuery({ queryKey: adminSessionKey, queryFn: getAdminSession, staleTime: Infinity, retry: false, enabled: IS_LIVE });
}

/** Everything cached for the signed-in staff member goes; the session query itself is refetched by the guard. */
export function forgetAdminSession(qc: QueryClient): void {
  qc.removeQueries({ queryKey: ['admin'] });
}

/**
 * A 401 from the session request itself is the guard's answer (go to sign-in), not news: resetting then would
 * refetch, get 401 again and loop forever. Only a refusal on another call while the session looked valid resets.
 */
export function shouldResetAdminSession(qc: QueryClient): boolean {
  const state = qc.getQueryState(adminSessionKey);
  return state?.status === 'success' && state.fetchStatus !== 'fetching';
}

export function installAdminSessionGuard(): void {
  setAdminUnauthenticatedListener(() => {
    // Back to "unknown": the guard asks the API again and routes by its answer (login, two-factor, blocked).
    if (shouldResetAdminSession(queryClient)) void queryClient.resetQueries({ queryKey: adminSessionKey });
  });
}

/** After a sign-in step succeeds: forget the old answer so the guard asks again instead of showing a stale refusal. */
export function useRefreshAdminSession(): () => Promise<void> {
  const qc = useQueryClient();
  return () => qc.resetQueries({ queryKey: adminSessionKey });
}
