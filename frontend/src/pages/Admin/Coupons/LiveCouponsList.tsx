/** Live coupons (10_SaaS_Admin §3.4, plan Task 22): list, create and archive. Partner attribution is not provided by the API, so it is not shown. */
import React, { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { useAdminSession } from '@/data/adminSession';
import { archiveCoupon, createCoupon, listCoupons } from '@/api/live/platform/catalogue';
import { createIntent } from '@/api/idempotency';
import { ApiError } from '@/api/errors';
import { buildCouponBody, describeDiscount, describeDuration, emptyCouponForm, type CouponForm, type Fields } from '@/api/adapters/platform/catalogue';
import { FormError, LoadError, Loading, primary, secondary } from '@/components/live/form';
import { formatDate } from '@/lib/format';
import { CouponFormView } from './CouponsViews';

const serverFields = (e: unknown): Fields => {
  if (!(e instanceof ApiError) || !e.errors) return {};
  const map: Record<string, string> = { percent: 'percent', amount: 'amount', currency: 'amount', duration_months: 'durationMonths', max_redemptions: 'maxRedemptions', expires_at: 'expiresAt', code: 'code' };
  return Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [map[k] ?? k, v[0] ?? '']));
};

export const LiveCouponsList: React.FC = () => {
  const qc = useQueryClient();
  const session = useAdminSession();
  const canManage = session.data?.can.includes('coupons.manage') ?? false;
  const q = useQuery({ queryKey: ['admin', 'coupons'], queryFn: listCoupons });
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState<CouponForm>(emptyCouponForm);
  const [errors, setErrors] = useState<Fields>({});
  const intent = useRef(createIntent());

  const create = useMutation({
    mutationFn: (body: Parameters<typeof createCoupon>[0]) => createCoupon(body, intent.current),
    onSuccess: () => {
      toast.success('Coupon created.');
      setCreating(false);
      setForm(emptyCouponForm());
      void qc.invalidateQueries({ queryKey: ['admin', 'coupons'] });
    },
    onError: (e) => setErrors(serverFields(e)),
  });
  const archive = useMutation({
    mutationFn: (id: string) => archiveCoupon(id),
    onSuccess: () => {
      toast.success('Coupon archived.');
      void qc.invalidateQueries({ queryKey: ['admin', 'coupons'] });
    },
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const built = buildCouponBody(form);
    if (!built.ok) return setErrors(built.errors);
    setErrors({});
    create.mutate(built.body);
  };

  return (
    <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 text-text-primary">
      <div className="flex items-center gap-2 border-b border-border pb-2">
        <h1 className="text-xl font-semibold">Coupons and discounts</h1>
        {canManage && !creating && <button type="button" className={`ml-auto ${primary}`} onClick={() => setCreating(true)}>Create coupon</button>}
      </div>
      {creating && (
        <>
          <CouponFormView form={form} errors={errors} onChange={setForm} onSubmit={submit} onCancel={() => setCreating(false)} busy={create.isPending} />
          <FormError error={create.error && Object.keys(serverFields(create.error)).length === 0 ? create.error : null} />
        </>
      )}
      <FormError error={archive.error} />
      {q.isPending && <Loading what="Loading coupons" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && q.data.length === 0 && <p className="text-[13px] text-text-muted">No coupons yet.</p>}
      {q.data && q.data.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-border bg-surface">
          <table className="w-full border-collapse text-left text-xs">
            <thead>
              <tr className="h-8 border-b border-border bg-surface-neutral/60 text-[11px] font-semibold uppercase text-text-muted">
                <th className="px-3">Code</th><th className="px-3">Discount</th><th className="px-3">Duration</th>
                <th className="px-3">Redeemed</th><th className="px-3">Expires</th><th className="px-3">Status</th>{canManage && <th className="px-3 text-right">Actions</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-border/60">
              {q.data.map((c) => (
                <tr key={c.id} className="h-9">
                  <td className="px-3 font-mono font-semibold text-text-secondary">{c.code}</td>
                  <td className="px-3 font-mono">{describeDiscount(c)}</td>
                  <td className="px-3 text-text-secondary">{describeDuration(c)}</td>
                  <td className="px-3 font-mono text-text-secondary">{c.redeemed_count} / {c.max_redemptions ?? '∞'}</td>
                  <td className="px-3 text-text-secondary">{c.expires_at ? formatDate(c.expires_at) : 'Never'}</td>
                  <td className="px-3">{c.is_active ? 'Active' : 'Archived'}</td>
                  {canManage && (
                    <td className="px-3 text-right">
                      {c.is_active && (
                        <button
                          type="button" className={`${secondary} h-7 px-2 text-xs`} disabled={archive.isPending}
                          onClick={() => { if (window.confirm(`Archive ${c.code}? It can no longer be redeemed.`)) archive.mutate(c.id); }}
                        >
                          Archive
                        </button>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
};
