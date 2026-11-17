/**
 * The Subscription tab (10 §3.2). Reads `GET /billing/status`: without Stripe keys the panel says "Stripe not connected",
 * disables Stripe collection and keeps offline invoicing working (plan G2). Money is integer minor units shown with the
 * currency's own exponent.
 */
import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { formatMoney } from '@/lib/format';
import { describeError } from '@/api/messages';
import { tenantsApi } from '@/api/live/platform/tenants';
import {
  emptySubscribeForm, toSubscribeBody, validateSubscribe, PLATFORM_CURRENCIES, type SubscribeForm,
} from '@/api/adapters/platform/tenantForms';
import type { PlanOption, Subscription } from '@/api/adapters/platform/tenants';
import { Field, inputCls, btnPrimary, btnGhost, ErrorLine, dateOrDash } from './tenantUi';

type ActionId = 'change-plan' | 'extend-trial' | 'convert-trial' | 'pause' | 'resume' | 'cancel-end' | 'cancel-now' | 'apply-coupon';
const ACTIONS: Array<{ id: ActionId; label: string; when: (s: Subscription) => boolean }> = [
  { id: 'change-plan', label: 'Change plan', when: () => true },
  { id: 'extend-trial', label: 'Extend trial', when: (s) => s.status === 'trialing' || s.status === 'trial' },
  { id: 'convert-trial', label: 'Convert trial', when: (s) => s.status === 'trialing' || s.status === 'trial' },
  { id: 'pause', label: 'Pause', when: (s) => s.status === 'active' },
  { id: 'resume', label: 'Resume', when: (s) => s.status === 'paused' || s.cancelAt !== undefined },
  { id: 'cancel-end', label: 'Cancel at period end', when: () => true },
  { id: 'cancel-now', label: 'Cancel now', when: () => true },
  { id: 'apply-coupon', label: 'Apply coupon', when: () => true },
];

/** Which endpoint and body an action posts; pure so the panel stays a view. */
export function actionRequest(id: ActionId, f: { reason: string; days: string; code: string; planCode: string; interval: string }): { action: string; body: Record<string, unknown> } {
  const reason = f.reason.trim() || undefined;
  switch (id) {
    case 'change-plan': return { action: 'change-plan', body: { plan_code: f.planCode, interval: f.interval || undefined, reason } };
    case 'extend-trial': return { action: 'extend-trial', body: { days: Number(f.days), reason } };
    case 'convert-trial': return { action: 'convert-trial', body: { reason } };
    case 'pause': return { action: 'pause', body: { reason } };
    case 'resume': return { action: 'resume', body: { reason } };
    case 'cancel-end': return { action: 'cancel', body: { at_period_end: true, reason } };
    case 'cancel-now': return { action: 'cancel', body: { at_period_end: false, reason } };
    case 'apply-coupon': return { action: 'apply-coupon', body: { code: f.code.trim(), reason } };
  }
}

const ActionForm: React.FC<{ id: ActionId; plans: PlanOption[]; busy: boolean; onRun: (r: { action: string; body: Record<string, unknown> }) => void; onCancel: () => void }> = ({ id, plans, busy, onRun, onCancel }) => {
  const [f, setF] = useState({ reason: '', days: '7', code: '', planCode: '', interval: 'month' });
  const set = (p: Partial<typeof f>) => setF((x) => ({ ...x, ...p }));
  const invalid = (id === 'change-plan' && !f.planCode) || (id === 'extend-trial' && !(Number(f.days) >= 1)) || (id === 'apply-coupon' && !f.code.trim());
  return (
    <form
      className="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3 border border-border rounded-md bg-surface"
      onSubmit={(e) => {
        e.preventDefault();
        onRun(actionRequest(id, f));
      }}
    >
      {id === 'change-plan' && (
        <>
          <Field label="New plan" htmlFor="sp-plan">
            <select id="sp-plan" className={inputCls} value={f.planCode} onChange={(e) => set({ planCode: e.target.value })}>
              <option value="">Choose a plan</option>
              {plans.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
            </select>
          </Field>
          <Field label="Interval" htmlFor="sp-int">
            <select id="sp-int" className={inputCls} value={f.interval} onChange={(e) => set({ interval: e.target.value })}>
              <option value="month">Monthly</option>
              <option value="year">Annual</option>
            </select>
          </Field>
        </>
      )}
      {id === 'extend-trial' && (
        <Field label="Days to add" htmlFor="sp-days">
          <input id="sp-days" inputMode="numeric" className={inputCls} value={f.days} onChange={(e) => set({ days: e.target.value })} />
        </Field>
      )}
      {id === 'apply-coupon' && (
        <Field label="Coupon code" htmlFor="sp-code">
          <input id="sp-code" className={inputCls} value={f.code} onChange={(e) => set({ code: e.target.value })} />
        </Field>
      )}
      <div className="sm:col-span-2">
        <Field label="Reason (optional)" htmlFor="sp-reason">
          <input id="sp-reason" className={inputCls} value={f.reason} onChange={(e) => set({ reason: e.target.value })} />
        </Field>
      </div>
      <div className="sm:col-span-2 flex justify-end gap-2">
        <button type="button" className={btnGhost} onClick={onCancel}>Back</button>
        <button type="submit" className={btnPrimary} disabled={busy || invalid}>{busy ? 'Saving…' : 'Confirm'}</button>
      </div>
    </form>
  );
};

const SubscribeFormView: React.FC<{ plans: PlanOption[]; stripeConfigured: boolean; busy: boolean; onSubscribe: (f: SubscribeForm) => void }> = ({ plans, stripeConfigured, busy, onSubscribe }) => {
  const [f, setF] = useState<SubscribeForm>(emptySubscribeForm);
  const errors = validateSubscribe(f, { stripeConfigured });
  const [shown, setShown] = useState(false);
  const set = (p: Partial<SubscribeForm>) => setF((x) => ({ ...x, ...p }));
  return (
    <form
      className="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3 border border-border rounded-md bg-surface"
      onSubmit={(e) => {
        e.preventDefault();
        setShown(true);
        if (Object.keys(errors).length === 0) onSubscribe(f);
      }}
    >
      <Field label="Plan" htmlFor="sb-plan" error={shown ? errors.planCode : undefined}>
        <select id="sb-plan" className={inputCls} value={f.planCode} onChange={(e) => set({ planCode: e.target.value })}>
          <option value="">Choose a plan</option>
          {plans.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
        </select>
      </Field>
      <Field label="Collection" htmlFor="sb-col" error={shown ? errors.collection : undefined}>
        <select id="sb-col" className={inputCls} value={f.collection} onChange={(e) => set({ collection: e.target.value as SubscribeForm['collection'] })}>
          <option value="stripe" disabled={!stripeConfigured}>Stripe (card)</option>
          <option value="offline">Invoiced offline</option>
        </select>
      </Field>
      <Field label="Currency" htmlFor="sb-cur">
        <select id="sb-cur" className={inputCls} value={f.currency} onChange={(e) => set({ currency: e.target.value })}>
          {PLATFORM_CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
      </Field>
      <Field label="Interval" htmlFor="sb-int">
        <select id="sb-int" className={inputCls} value={f.interval} onChange={(e) => set({ interval: e.target.value as 'month' | 'year' })}>
          <option value="month">Monthly</option>
          <option value="year">Annual</option>
        </select>
      </Field>
      <Field label="Custom price (optional)" htmlFor="sb-amt" error={shown ? errors.customAmount : undefined}>
        <input id="sb-amt" inputMode="decimal" className={inputCls} value={f.customAmount} onChange={(e) => set({ customAmount: e.target.value })} />
      </Field>
      <Field label="Coupon code (optional)" htmlFor="sb-coupon">
        <input id="sb-coupon" className={inputCls} value={f.couponCode} onChange={(e) => set({ couponCode: e.target.value })} />
      </Field>
      <div className="sm:col-span-2 flex justify-end">
        <button type="submit" className={btnPrimary} disabled={busy}>{busy ? 'Saving…' : 'Start subscription'}</button>
      </div>
    </form>
  );
};

export interface SubscriptionPanelViewProps {
  subscription: Subscription | null;
  stripeConfigured: boolean;
  canManage: boolean;
  plans: PlanOption[];
  busy: boolean;
  error: string | null;
  onAction: (r: { action: string; body: Record<string, unknown> }) => void;
  onSubscribe: (f: SubscribeForm) => void;
}

export const SubscriptionPanelView: React.FC<SubscriptionPanelViewProps> = ({ subscription: s, stripeConfigured, canManage, plans, busy, error, onAction, onSubscribe }) => {
  const [active, setActive] = useState<ActionId | null>(null);
  return (
    <div className="flex flex-col gap-3">
      {!stripeConfigured && (
        <div role="status" className="px-3 py-2 rounded-md border border-warning/40 bg-warning-soft text-[12px] text-text-primary">
          <strong>Stripe not connected.</strong> Card collection is unavailable; subscriptions can still be invoiced offline.
        </div>
      )}
      {s ? (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-[13px]">
          <div><div className="text-[11px] text-text-muted">Plan</div><div className="font-medium">{s.planName}</div></div>
          <div><div className="text-[11px] text-text-muted">Status</div><div>{s.status}</div></div>
          <div><div className="text-[11px] text-text-muted">Per month</div><div className="font-mono">{formatMoney(s.monthlyAmount, s.currency)}</div></div>
          <div><div className="text-[11px] text-text-muted">Collection</div><div>{s.collection === 'stripe' ? 'Stripe' : 'Invoiced offline'}</div></div>
          <div><div className="text-[11px] text-text-muted">Interval</div><div>{s.interval === 'year' ? 'Annual' : 'Monthly'}</div></div>
          <div><div className="text-[11px] text-text-muted">Trial ends</div><div>{dateOrDash(s.trialEndsAt)}</div></div>
          <div><div className="text-[11px] text-text-muted">Period ends</div><div>{dateOrDash(s.currentPeriodEnd)}</div></div>
          <div><div className="text-[11px] text-text-muted">Coupon</div><div>{s.couponCode ?? '—'}</div></div>
          {s.cancelAt && <div className="col-span-2"><div className="text-[11px] text-text-muted">Cancels on</div><div>{dateOrDash(s.cancelAt)}</div></div>}
          {s.pauseUntil && <div className="col-span-2"><div className="text-[11px] text-text-muted">Paused until</div><div>{dateOrDash(s.pauseUntil)}</div></div>}
        </div>
      ) : (
        <p className="text-[13px] text-text-secondary">This tenant has no subscription yet.</p>
      )}
      {canManage && s && (
        <>
          <div className="flex flex-wrap gap-2">
            {ACTIONS.filter((a) => a.when(s)).map((a) => (
              <button key={a.id} type="button" className={btnGhost} onClick={() => setActive(a.id)} aria-pressed={active === a.id}>
                {a.label}
              </button>
            ))}
          </div>
          {active && <ActionForm key={active} id={active} plans={plans} busy={busy} onCancel={() => setActive(null)} onRun={(r) => { onAction(r); setActive(null); }} />}
        </>
      )}
      {canManage && !s && <SubscribeFormView plans={plans} stripeConfigured={stripeConfigured} busy={busy} onSubscribe={onSubscribe} />}
      <ErrorLine message={error} />
    </div>
  );
};

export const SubscriptionPanel: React.FC<{ tenantId: string; canManage: boolean }> = ({ tenantId, canManage }) => {
  const qc = useQueryClient();
  const status = useQuery({ queryKey: ['admin', 'billing-status'], queryFn: () => tenantsApi.billingStatus(), staleTime: 60_000 });
  const sub = useQuery({ queryKey: ['admin', 'tenants', tenantId, 'subscription'], queryFn: () => tenantsApi.subscription(tenantId) });
  const plans = useQuery({ queryKey: ['admin', 'plans', 'options'], queryFn: () => tenantsApi.plans(), staleTime: 60_000 });
  const done = (s: Subscription | null) => {
    qc.setQueryData(['admin', 'tenants', tenantId, 'subscription'], s);
    void qc.invalidateQueries({ queryKey: ['admin', 'tenants', tenantId] });
    toast.success('Subscription updated.');
  };
  const run = useMutation({ mutationFn: (r: { action: string; body: Record<string, unknown> }) => tenantsApi.subscriptionAction(tenantId, r.action, r.body), onSuccess: done });
  const create = useMutation({ mutationFn: (f: SubscribeForm) => tenantsApi.subscriptionAction(tenantId, '', toSubscribeBody(f)), onSuccess: done });

  if (sub.isPending || status.isPending) return <p className="text-[13px] text-text-muted">Loading…</p>;
  if (sub.error) return <ErrorLine message={describeError(sub.error)} />;
  const err = run.error ?? create.error;
  return (
    <SubscriptionPanelView
      subscription={sub.data ?? null}
      stripeConfigured={status.data?.stripeConfigured ?? false}
      canManage={canManage}
      plans={plans.data ?? []}
      busy={run.isPending || create.isPending}
      error={err ? describeError(err) : null}
      onAction={(r) => run.mutate(r)}
      onSubscribe={(f) => create.mutate(f)}
    />
  );
};
