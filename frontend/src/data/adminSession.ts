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

export function installAdminSessionGuard(): void {
  setAdminUnauthenticatedListener(() => {
    // Back to "unknown": the guard asks the API again and routes by its answer (login, two-factor, blocked).
    void queryClient.resetQueries({ queryKey: adminSessionKey });
  });
}

/** After a sign-in step succeeds: forget the old answer so the guard asks again instead of showing a stale refusal. */
export function useRefreshAdminSession(): () => Promise<void> {
  const qc = useQueryClient();
  return () => qc.resetQueries({ queryKey: adminSessionKey });
}
