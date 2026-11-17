import { describe, expect, it, vi } from 'vitest';
import { QueryClient } from '@tanstack/react-query';
import { shouldResetAdminSession, adminSessionKey } from './adminSession';

describe('admin session guard', () => {
  it('does not reset the session query for a 401 the session request itself produced (no refetch loop)', () => {
    const qc = new QueryClient();
    qc.getQueryCache().build(qc, { queryKey: adminSessionKey }).setState({ fetchStatus: 'fetching' });
    expect(shouldResetAdminSession(qc)).toBe(false);
  });

  it('does not reset again once the session query has settled on the 401', () => {
    const qc = new QueryClient();
    qc.getQueryCache().build(qc, { queryKey: adminSessionKey }).setState({ status: 'error', fetchStatus: 'idle' });
    expect(shouldResetAdminSession(qc)).toBe(false);
  });

  it('resets when another admin call is refused while the session looked signed in', () => {
    const qc = new QueryClient();
    qc.getQueryCache().build(qc, { queryKey: adminSessionKey }).setState({ status: 'success', fetchStatus: 'idle', data: {} as never });
    expect(shouldResetAdminSession(qc)).toBe(true);
    vi.restoreAllMocks();
  });
});
