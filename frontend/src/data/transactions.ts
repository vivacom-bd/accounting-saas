/**
 * Fund transfers, write-offs, recoveries and provisioning, live mode (plan 3c.8 to 3c.12a). Query keys start with the family, then the
 * company id. A posted document changes the books, so a transition refreshes every screen that reads balances: invoices, banking,
 * reports, the approvals inbox and the other transaction families.
 */
import { useMemo } from 'react';
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query';
import { cursorPaging, type CursorPage } from '@/api/cursor';
import * as live from '@/api/live/transactions';
import type { Intent } from '@/api/idempotency';
import type { TxFamily } from '@/api/adapters/transactions';
import { approvalsKey } from './journals';
import { useRouteCompany } from './session';

export const txKey = (companyId: string, family: TxFamily) => [family, companyId] as const;

const BOOK_FAMILIES = ['invoices', 'bills', 'settlements', 'banking', 'reports', 'contacts', 'fund-transfers', 'write-offs', 'write-off-recoveries', 'provision-runs', 'periods', 'fiscal'] as const;

export function useTxList<T>(family: TxFamily, filters: live.TxQuery = {}) {
  const company = useRouteCompany();
  const clean = Object.fromEntries(Object.entries(filters).filter(([, v]) => v)) as live.TxQuery;
  const q = useInfiniteQuery({
    queryKey: [...txKey(company.id, family), 'list', clean],
    queryFn: ({ pageParam }) => live.listTx<T>(company.id, family, clean, pageParam),
    ...cursorPaging,
    getNextPageParam: (last: CursorPage<T>) => last.meta.next_cursor ?? undefined,
  });
  const rows = useMemo(() => (q.data?.pages ?? []).flatMap((p) => p.data), [q.data]);
  return {
    rows,
    isLoading: q.isLoading,
    error: q.error,
    refetch: () => void q.refetch(),
    hasMore: Boolean(q.hasNextPage),
    loadMore: () => void q.fetchNextPage(),
    isLoadingMore: q.isFetchingNextPage,
  };
}

export function useTx<T>(family: TxFamily, id: string | undefined) {
  const company = useRouteCompany();
  const q = useQuery({ queryKey: [...txKey(company.id, family), 'one', id], queryFn: () => live.getTx<T>(company.id, family, id as string), enabled: Boolean(id), staleTime: 0 });
  return { data: q.data ?? null, isLoading: q.isLoading, error: q.error, refetch: () => void q.refetch() };
}

export function useTxActions<T>(family: TxFamily) {
  const company = useRouteCompany();
  const qc = useQueryClient();
  const refresh = () => {
    for (const root of BOOK_FAMILIES) void qc.invalidateQueries({ queryKey: [root, company.id] });
    void qc.invalidateQueries({ queryKey: approvalsKey(company.id) });
  };
  // What the server answered is the document now: put it in the cache so the next screen never shows the old state.
  const after = async (p: Promise<T & { id: string }>): Promise<T & { id: string }> => {
    const r = await p;
    qc.setQueryData([...txKey(company.id, family), 'one', r.id], r);
    refresh();
    return r;
  };
  return {
    create: (body: unknown, intent: Intent) => after(live.createTx<T & { id: string }>(company.id, family, body, intent)),
    update: (id: string, body: unknown, intent: Intent) => after(live.updateTx<T & { id: string }>(company.id, family, id, body, intent)),
    /** Writes nothing on the server, so no refresh. */
    preview: (id: string, intent: Intent) => live.previewTx(company.id, family as Exclude<TxFamily, 'provision-runs'>, id, intent),
    transition: (id: string, verb: live.TxVerb, body: Record<string, unknown>, intent: Intent) => after(live.transitionTx<T & { id: string }>(company.id, family, id, verb, body, intent)),
  };
}

/* ---------------------------------------------------------------- provisioning */

export const provisionRatesKey = (companyId: string) => ['provision-rates', companyId] as const;

export function useProvisionRates() {
  const company = useRouteCompany();
  const q = useQuery({ queryKey: provisionRatesKey(company.id), queryFn: () => live.getProvisionRates(company.id), retry: false });
  return { data: q.data ?? null, isLoading: q.isLoading, error: q.error, refetch: () => void q.refetch() };
}

export function useProvisionActions() {
  const company = useRouteCompany();
  const qc = useQueryClient();
  return {
    saveRates: async (body: unknown, intent: Intent) => {
      const r = await live.saveProvisionRates(company.id, body, intent);
      await qc.invalidateQueries({ queryKey: provisionRatesKey(company.id) });
      return r;
    },
    preview: (asOf: string, intent: Intent) => live.previewProvision(company.id, asOf, intent),
  };
}

