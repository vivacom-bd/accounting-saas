/** Presentational coupon form: a coupon is a percentage XOR an amount in one currency, so only the chosen kind is asked for. */
import React from 'react';
import { Field, field, primary, secondary } from '@/components/live/form';
import { CURRENCIES, type CouponForm, type Fields } from '@/api/adapters/platform/catalogue';

export interface CouponFormProps {
  form: CouponForm;
  errors: Fields;
  onChange: (next: CouponForm) => void;
  onSubmit: (e: React.FormEvent) => void;
  onCancel: () => void;
  busy: boolean;
}

export const CouponFormView: React.FC<CouponFormProps> = ({ form, errors, onChange, onSubmit, onCancel, busy }) => (
  <form onSubmit={onSubmit} className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4" aria-label="New coupon">
    <Field id="coupon-code" label="Code" error={errors.code} hint="Letters, digits, dashes and underscores. Saved in capitals.">
      <input id="coupon-code" className={`${field} font-mono uppercase`} autoComplete="off" value={form.code} onChange={(e) => onChange({ ...form, code: e.target.value })} />
    </Field>

    <fieldset className="flex flex-col gap-2">
      <legend className="text-[13px] font-medium text-text-primary">Discount</legend>
      <div className="flex gap-4 text-[13px]">
        <label htmlFor="coupon-mode-percent" className="flex items-center gap-2">
          <input id="coupon-mode-percent" type="radio" name="coupon-mode" checked={form.mode === 'percent'} onChange={() => onChange({ ...form, mode: 'percent' })} />
          Percentage
        </label>
        <label htmlFor="coupon-mode-amount" className="flex items-center gap-2">
          <input id="coupon-mode-amount" type="radio" name="coupon-mode" checked={form.mode === 'amount'} onChange={() => onChange({ ...form, mode: 'amount' })} />
          Fixed amount
        </label>
      </div>
      {form.mode === 'percent' ? (
        <Field id="coupon-percent" label="Percent off (1 to 100)" error={errors.percent}>
          <input id="coupon-percent" inputMode="numeric" className={field} value={form.percent} onChange={(e) => onChange({ ...form, percent: e.target.value })} />
        </Field>
      ) : (
        <div className="grid grid-cols-2 gap-3">
          <Field id="coupon-amount" label="Amount off" error={errors.amount}>
            <input id="coupon-amount" inputMode="decimal" autoComplete="off" className={field} value={form.amount} onChange={(e) => onChange({ ...form, amount: e.target.value })} />
          </Field>
          <Field id="coupon-currency" label="Currency">
            <select id="coupon-currency" className={field} value={form.currency} onChange={(e) => onChange({ ...form, currency: e.target.value as CouponForm['currency'] })}>
              {CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
          </Field>
        </div>
      )}
    </fieldset>

    <div className="grid grid-cols-2 gap-3">
      <Field id="coupon-duration" label="Duration">
        <select id="coupon-duration" className={field} value={form.duration} onChange={(e) => onChange({ ...form, duration: e.target.value as CouponForm['duration'] })}>
          <option value="once">Once</option>
          <option value="repeating">Repeating</option>
          <option value="forever">Forever</option>
        </select>
      </Field>
      {form.duration === 'repeating' && (
        <Field id="coupon-months" label="Months (1 to 36)" error={errors.durationMonths}>
          <input id="coupon-months" inputMode="numeric" className={field} value={form.durationMonths} onChange={(e) => onChange({ ...form, durationMonths: e.target.value })} />
        </Field>
      )}
    </div>

    <div className="grid grid-cols-2 gap-3">
      <Field id="coupon-max" label="Maximum redemptions" error={errors.maxRedemptions} hint="Empty means no limit.">
        <input id="coupon-max" inputMode="numeric" className={field} value={form.maxRedemptions} onChange={(e) => onChange({ ...form, maxRedemptions: e.target.value })} />
      </Field>
      <Field id="coupon-expires" label="Expires on" error={errors.expiresAt} hint="Empty means it does not expire.">
        <input id="coupon-expires" type="date" className={field} value={form.expiresAt} onChange={(e) => onChange({ ...form, expiresAt: e.target.value })} />
      </Field>
    </div>

    <Field id="coupon-plans" label="Only for these plan codes" error={errors.planCodes} hint="Separate with spaces or commas. Empty means every plan.">
      <input id="coupon-plans" className={`${field} font-mono`} value={form.planCodes} onChange={(e) => onChange({ ...form, planCodes: e.target.value })} />
    </Field>
    <label htmlFor="coupon-new-only" className="flex items-center gap-2 text-[13px]">
      <input id="coupon-new-only" type="checkbox" checked={form.newCustomersOnly} onChange={(e) => onChange({ ...form, newCustomersOnly: e.target.checked })} />
      New customers only
    </label>

    <div className="flex gap-2">
      <button type="submit" disabled={busy} className={primary}>{busy ? 'Saving…' : 'Create coupon'}</button>
      <button type="button" onClick={onCancel} className={secondary}>Cancel</button>
    </div>
  </form>
);
