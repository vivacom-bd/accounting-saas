/**
 * Provision a tenant (10 §3.2, plan G3): organisation + owner invite + plan + trial or "active, invoiced offline".
 * No password is set by staff and no company is created; the owner accepts the invitation and runs onboarding.
 * One Idempotency-Key per submit intent, kept across retries so a double click or a retry never makes two tenants.
 */
import React, { useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { createIntent } from '@/api/idempotency';
import { describeError } from '@/api/messages';
import { ApiError } from '@/api/errors';
import { tenantsApi, type OwnerInvitation, type ProvisionResult as ProvisionOutcome } from '@/api/live/platform/tenants';
import {
  emptyProvisionForm, toProvisionBody, validateProvision, PLATFORM_CURRENCIES, type ProvisionErrors, type ProvisionForm,
} from '@/api/adapters/platform/tenantForms';
import type { PlanOption, TenantRow } from '@/api/adapters/platform/tenants';
import { Field, inputCls, btnPrimary, btnGhost, ErrorLine, dateOrDash } from './tenantUi';

export const ProvisionFields: React.FC<{
  form: ProvisionForm;
  errors: ProvisionErrors;
  plans: PlanOption[];
  onChange: (patch: Partial<ProvisionForm>) => void;
}> = ({ form, errors, plans, onChange }) => (
  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <Field label="Organisation name" htmlFor="pv-org" error={errors.organisationName}>
      <input id="pv-org" className={inputCls} value={form.organisationName} onChange={(e) => onChange({ organisationName: e.target.value })} />
    </Field>
    <Field label="Country (two letters)" htmlFor="pv-country" error={errors.country}>
      <input id="pv-country" className={inputCls} maxLength={2} value={form.country} onChange={(e) => onChange({ country: e.target.value })} />
    </Field>
    <Field label="Owner name" htmlFor="pv-owner" error={errors.ownerName}>
      <input id="pv-owner" className={inputCls} value={form.ownerName} onChange={(e) => onChange({ ownerName: e.target.value })} />
    </Field>
    <Field label="Owner email" htmlFor="pv-email" error={errors.ownerEmail}>
      <input id="pv-email" type="email" className={inputCls} value={form.ownerEmail} onChange={(e) => onChange({ ownerEmail: e.target.value })} />
    </Field>
    <Field label="Plan" htmlFor="pv-plan" error={errors.planCode}>
      {plans.length > 0 ? (
        <select id="pv-plan" className={inputCls} value={form.planCode} onChange={(e) => onChange({ planCode: e.target.value })}>
          <option value="">Choose a plan</option>
          {plans.map((p) => (
            <option key={p.code} value={p.code}>
              {p.name}
            </option>
          ))}
        </select>
      ) : (
        <input id="pv-plan" className={inputCls} placeholder="Plan code" value={form.planCode} onChange={(e) => onChange({ planCode: e.target.value })} />
      )}
    </Field>
    <Field label="Billing" htmlFor="pv-billing">
      <select id="pv-billing" className={inputCls} value={form.billing} onChange={(e) => onChange({ billing: e.target.value as ProvisionForm['billing'] })}>
        <option value="trial">Start a trial</option>
        <option value="offline">Active, invoiced offline</option>
      </select>
    </Field>
    {form.billing === 'trial' ? (
      <Field label="Trial days (optional)" htmlFor="pv-trial" error={errors.trialDays} hint="Leave empty for the plan's default trial.">
        <input id="pv-trial" inputMode="numeric" className={inputCls} value={form.trialDays} onChange={(e) => onChange({ trialDays: e.target.value })} />
      </Field>
    ) : (
      <>
        <Field label="Currency" htmlFor="pv-cur">
          <select id="pv-cur" className={inputCls} value={form.currency} onChange={(e) => onChange({ currency: e.target.value })}>
            {PLATFORM_CURRENCIES.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Billing interval" htmlFor="pv-int">
          <select id="pv-int" className={inputCls} value={form.interval} onChange={(e) => onChange({ interval: e.target.value as 'month' | 'year' })}>
            <option value="month">Monthly</option>
            <option value="year">Annual</option>
          </select>
        </Field>
        <Field label="Custom price (optional)" htmlFor="pv-amt" error={errors.customAmount} hint="Replaces the plan price; entered in the currency's units.">
          <input id="pv-amt" inputMode="decimal" className={inputCls} value={form.customAmount} onChange={(e) => onChange({ customAmount: e.target.value })} />
        </Field>
      </>
    )}
    <div className="sm:col-span-2">
      <Field label="Internal note (optional)" htmlFor="pv-note">
        <textarea id="pv-note" rows={2} className={`${inputCls} h-auto py-1.5`} value={form.note} onChange={(e) => onChange({ note: e.target.value })} />
      </Field>
    </div>
  </div>
);

export const ProvisionResult: React.FC<{ tenant: TenantRow; ownerInvitation: OwnerInvitation; onOpen: () => void; onClose: () => void }> = ({ tenant, ownerInvitation, onOpen, onClose }) => (
  <div className="flex flex-col gap-3 text-[13px]">
    <p>
      <strong>{tenant.name}</strong> was created.
    </p>
    <p className="text-text-secondary">
      Invitation sent to <span className="font-mono">{ownerInvitation.email}</span>; it expires {dateOrDash(ownerInvitation.expiresAt)}. The owner sets their own password and runs onboarding.
    </p>
    <div className="flex justify-end gap-2">
      <button type="button" className={btnGhost} onClick={onClose}>
        Close
      </button>
      <button type="button" className={btnPrimary} onClick={onOpen}>
        Open tenant
      </button>
    </div>
  </div>
);

export const ProvisionTenantDialog: React.FC<{ open: boolean; onOpenChange: (o: boolean) => void; plans: PlanOption[]; onOpenTenant: (id: string) => void }> = ({
  open, onOpenChange, plans, onOpenTenant,
}) => {
  const qc = useQueryClient();
  const [form, setForm] = useState<ProvisionForm>(emptyProvisionForm);
  const [errors, setErrors] = useState<ProvisionErrors>({});
  const [result, setResult] = useState<ProvisionOutcome | null>(null);
  // One intent per dialog use: a retry after a failure reuses the key; settle() (on success) makes the next one new.
  const intent = useRef(createIntent());

  const m = useMutation({
    mutationFn: () => tenantsApi.provisionTenant(toProvisionBody(form), intent.current),
    onSuccess: (r) => {
      setResult(r);
      void qc.invalidateQueries({ queryKey: ['admin', 'tenants'] });
    },
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const errs = validateProvision(form);
    setErrors(errs);
    if (Object.keys(errs).length === 0) m.mutate();
  };
  const close = (o: boolean) => {
    onOpenChange(o);
    if (!o) {
      setForm(emptyProvisionForm());
      setErrors({});
      setResult(null);
      m.reset();
    }
  };
  const serverErrors = m.error instanceof ApiError ? m.error.errors : undefined;

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>New tenant</DialogTitle>
          <DialogDescription>Creates the organisation and invites its owner. No company is created; the owner runs onboarding.</DialogDescription>
        </DialogHeader>
        {result ? (
          <ProvisionResult tenant={result.tenant} ownerInvitation={result.ownerInvitation} onClose={() => close(false)} onOpen={() => { close(false); onOpenTenant(result.tenant.id); }} />
        ) : (
          <form onSubmit={submit} className="flex flex-col gap-3" noValidate>
            <ProvisionFields form={form} errors={{ ...Object.fromEntries(Object.entries(serverErrors ?? {}).map(([k, v]) => [k.replace(/_([a-z])/g, (_, c: string) => c.toUpperCase()), v[0]])), ...errors }} plans={plans} onChange={(p) => setForm((f) => ({ ...f, ...p }))} />
            <ErrorLine message={m.error ? describeError(m.error) : null} />
            <div className="flex justify-end gap-2">
              <button type="button" className={btnGhost} onClick={() => close(false)}>
                Cancel
              </button>
              <button type="submit" className={btnPrimary} disabled={m.isPending}>
                {m.isPending ? 'Creating…' : 'Create tenant'}
              </button>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
};
