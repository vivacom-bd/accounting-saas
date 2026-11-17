/**
 * Live tenants list (10 §3.2): filters become query params, the list is cursor-paged with "Load more". Only what the API
 * provides is shown: no health score, MRR or ticket counts.
 */
import React, { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { describeError } from '@/api/messages';
import { tenantsApi, type TenantListFilters } from '@/api/live/platform/tenants';
import type { PlanOption, TenantRow } from '@/api/adapters/platform/tenants';
import { LIFECYCLE_NAMES, LIFECYCLE_STATUSES } from '@/lib/tenantLifecycle';
import { useAdminSession } from '@/data/adminSession';
import { canDo } from '@/api/adapters/platform/session';
import { Field, inputCls, btnPrimary, btnGhost, LifecyclePill, ErrorLine, dateOrDash } from './tenantUi';
import { ProvisionTenantDialog } from './ProvisionTenantDialog';

export type ListFilters = Required<Pick<TenantListFilters, 'sort'>> & { status: string; plan: string; country: string; q: string };

export const TenantFilterBar: React.FC<{ filters: ListFilters; plans: PlanOption[]; onChange: (patch: Partial<ListFilters>) => void }> = ({ filters, plans, onChange }) => (
  <div className="grid grid-cols-2 md:grid-cols-5 gap-2 items-end">
    <Field label="Search" htmlFor="tf-q">
      <input id="tf-q" type="search" className={inputCls} placeholder="Name, slug or owner" value={filters.q} onChange={(e) => onChange({ q: e.target.value })} />
    </Field>
    <Field label="Status" htmlFor="tf-status">
      <select id="tf-status" className={inputCls} value={filters.status} onChange={(e) => onChange({ status: e.target.value })}>
        <option value="">All</option>
        {LIFECYCLE_STATUSES.map((s) => (
          <option key={s} value={s}>
            {LIFECYCLE_NAMES[s]}
          </option>
        ))}
      </select>
    </Field>
    <Field label="Plan" htmlFor="tf-plan">
      <select id="tf-plan" className={inputCls} value={filters.plan} onChange={(e) => onChange({ plan: e.target.value })}>
        <option value="">All</option>
        {plans.map((p) => (
          <option key={p.code} value={p.code}>
            {p.name}
          </option>
        ))}
      </select>
    </Field>
    <Field label="Country" htmlFor="tf-country">
      <input id="tf-country" className={inputCls} maxLength={2} placeholder="KE" value={filters.country} onChange={(e) => onChange({ country: e.target.value })} />
    </Field>
    <Field label="Sort" htmlFor="tf-sort">
      <select id="tf-sort" className={inputCls} value={filters.sort} onChange={(e) => onChange({ sort: e.target.value as ListFilters['sort'] })}>
        <option value="created_at">Newest first</option>
        <option value="name">Name</option>
      </select>
    </Field>
  </div>
);

export const TenantsTable: React.FC<{ rows: TenantRow[]; hasMore: boolean; loadingMore: boolean; onLoadMore: () => void; onOpen: (id: string) => void }> = ({ rows, hasMore, loadingMore, onLoadMore, onOpen }) => (
  <div className="flex flex-col gap-2">
    <div className="overflow-x-auto border border-border rounded-md bg-surface">
      <table className="w-full text-[12px]">
        <thead className="bg-surface-neutral text-text-secondary text-left">
          <tr>
            <th className="px-3 py-2 font-medium">Tenant</th>
            <th className="px-3 py-2 font-medium">Status</th>
            <th className="px-3 py-2 font-medium">Plan</th>
            <th className="px-3 py-2 font-medium">Owner</th>
            <th className="px-3 py-2 font-medium">Country</th>
            <th className="px-3 py-2 font-medium text-right">Companies</th>
            <th className="px-3 py-2 font-medium text-right">Users</th>
            <th className="px-3 py-2 font-medium">Created</th>
            <th className="px-3 py-2 font-medium">Last activity</th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && (
            <tr>
              <td colSpan={9} className="px-3 py-6 text-center text-text-muted">
                No tenants match these filters.
              </td>
            </tr>
          )}
          {rows.map((t) => (
            <tr key={t.id} className="border-t border-border hover:bg-surface-neutral cursor-pointer" onClick={() => onOpen(t.id)}>
              <td className="px-3 py-2">
                <button type="button" className="font-medium text-left hover:underline cursor-pointer" onClick={(e) => { e.stopPropagation(); onOpen(t.id); }}>
                  {t.name}
                </button>
                <div className="text-text-muted font-mono">{t.slug}</div>
              </td>
              <td className="px-3 py-2"><LifecyclePill status={t.status} /></td>
              <td className="px-3 py-2">{t.planName ?? '—'}</td>
              <td className="px-3 py-2">
                <div>{t.ownerName ?? '—'}</div>
                <div className="text-text-muted">{t.ownerEmail}</div>
              </td>
              <td className="px-3 py-2">{t.country ?? '—'}</td>
              <td className="px-3 py-2 text-right font-mono">{t.companiesCount}</td>
              <td className="px-3 py-2 text-right font-mono">{t.usersCount}</td>
              <td className="px-3 py-2">{dateOrDash(t.createdAt)}</td>
              <td className="px-3 py-2">{dateOrDash(t.lastActivityAt)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    {hasMore && (
      <div className="flex justify-center">
        <button type="button" className={btnGhost} onClick={onLoadMore} disabled={loadingMore}>
          {loadingMore ? 'Loading…' : 'Load more'}
        </button>
      </div>
    )}
  </div>
);

const DEFAULTS: ListFilters = { q: '', status: '', plan: '', country: '', sort: 'created_at' };

function useDebounced<T>(value: T, ms: number): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

export const LiveTenantsList: React.FC = () => {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const session = useAdminSession().data;
  const [provisionOpen, setProvisionOpen] = useState(false);

  const filters: ListFilters = {
    q: params.get('q') ?? '',
    status: params.get('status') ?? '',
    plan: params.get('plan') ?? '',
    country: params.get('country') ?? '',
    sort: params.get('sort') === 'name' ? 'name' : 'created_at',
  };
  const applied = useDebounced(filters, 250);
  const change = (patch: Partial<ListFilters>) => {
    const next = { ...filters, ...patch };
    const sp = new URLSearchParams();
    for (const [k, v] of Object.entries(next)) if (v && v !== DEFAULTS[k as keyof ListFilters]) sp.set(k, v);
    setParams(sp, { replace: true });
  };

  const plans = useQuery({ queryKey: ['admin', 'plans', 'options'], queryFn: () => tenantsApi.plans(), staleTime: 60_000 });
  const list = useInfiniteQuery({
    queryKey: ['admin', 'tenants', 'list', applied],
    queryFn: ({ pageParam }) => tenantsApi.listTenants(applied, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (last) => last.nextCursor ?? undefined,
  });
  const rows = list.data?.pages.flatMap((p) => p.rows) ?? [];
  const canProvision = canDo({ can: session?.can ?? [] }, 'tenants.provision');

  return (
    <div className="flex-1 flex flex-col p-4 space-y-3 max-w-7xl mx-auto w-full text-text-primary">
      <div className="flex items-center justify-between gap-2 pb-2 border-b border-border">
        <div>
          <h1 className="text-xl font-semibold">Tenants</h1>
          <p className="text-xs text-text-muted mt-0.5">Customer organisations, their plans and lifecycle.</p>
        </div>
        {canProvision && (
          <button type="button" className={btnPrimary} onClick={() => setProvisionOpen(true)}>
            New tenant
          </button>
        )}
      </div>
      <TenantFilterBar filters={filters} plans={plans.data ?? []} onChange={change} />
      {list.isPending ? (
        <p className="text-[13px] text-text-muted">Loading…</p>
      ) : list.error ? (
        <ErrorLine message={describeError(list.error)} />
      ) : (
        <TenantsTable rows={rows} hasMore={!!list.hasNextPage} loadingMore={list.isFetchingNextPage} onLoadMore={() => void list.fetchNextPage()} onOpen={(id) => navigate(`/admin/tenants/${id}`)} />
      )}
      {canProvision && <ProvisionTenantDialog open={provisionOpen} onOpenChange={setProvisionOpen} plans={plans.data ?? []} onOpenTenant={(id) => navigate(`/admin/tenants/${id}`)} />}
    </div>
  );
};
