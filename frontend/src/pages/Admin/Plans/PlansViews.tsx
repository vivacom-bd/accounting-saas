/** Presentational pieces of the live plans screen: a version card (read-only) and the new-version form. No data fetching here. */
import React from 'react';
import { Field, field, primary, secondary } from '@/components/live/form';
import { formatDate } from '@/lib/format';
import {
  CURRENCIES, ENTITLEMENT_KEYS, ENTITLEMENT_LABELS, INTERVALS, LIMIT_KEYS, LIMIT_LABELS, formatPlanPrice,
  type ApiPlanVersion, type Fields, type VersionForm,
} from '@/api/adapters/platform/catalogue';

export const VersionCard: React.FC<{ version: ApiPlanVersion; effective: boolean }> = ({ version, effective }) => (
  <section className="rounded-lg border border-border bg-surface p-4 text-[13px]" aria-label={`Version ${version.version}`}>
    <header className="flex items-center gap-2">
      <h4 className="font-semibold text-text-primary">Version {version.version}</h4>
      {effective ? (
        <span className="px-1.5 py-px rounded-[3px] bg-surface-neutral text-text-secondary text-[11px] font-medium">Read-only, in force since {formatDate(version.effective_from)}</span>
      ) : (
        <span className="px-1.5 py-px rounded-[3px] bg-warning-soft text-warning text-[11px] font-medium">Scheduled for {formatDate(version.effective_from)}</span>
      )}
      <span className="ml-auto text-text-muted">Trial {version.trial_days} days</span>
    </header>

    <table className="mt-3 w-full text-left text-xs">
      <thead>
        <tr className="text-text-muted text-[11px] uppercase">
          <th className="py-1 font-semibold">Currency</th>
          <th className="py-1 font-semibold">Monthly</th>
          <th className="py-1 font-semibold">Annual</th>
        </tr>
      </thead>
      <tbody>
        {CURRENCIES.map((c) => (
          <tr key={c} className="border-t border-border/60">
            <td className="py-1 font-mono text-text-secondary">{c}</td>
            <td className="py-1 font-mono">{formatPlanPrice(version.prices[c]?.monthly, c)}</td>
            <td className="py-1 font-mono">{formatPlanPrice(version.prices[c]?.annual, c)}</td>
          </tr>
        ))}
      </tbody>
    </table>

    <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
      {LIMIT_KEYS.map((k) => (
        <div key={k} className="flex justify-between gap-2">
          <dt className="text-text-muted">{LIMIT_LABELS[k]}</dt>
          <dd className="font-mono text-text-primary">{version.limits[k] == null ? 'Unlimited' : version.limits[k]}</dd>
        </div>
      ))}
    </dl>

    <ul className="mt-3 flex flex-wrap gap-1.5 text-[11px]">
      {ENTITLEMENT_KEYS.map((k) => (
        <li key={k} className={version.entitlements[k] ? 'px-1.5 py-px rounded-[3px] bg-success-soft text-success' : 'px-1.5 py-px rounded-[3px] bg-surface-neutral text-text-muted line-through'}>
          {ENTITLEMENT_LABELS[k]}
        </li>
      ))}
    </ul>
  </section>
);

export interface VersionFormProps {
  form: VersionForm;
  errors: Fields;
  onChange: (next: VersionForm) => void;
  onSubmit: (e: React.FormEvent) => void;
  onCancel: () => void;
  busy: boolean;
  today: string;
  submitLabel?: string;
}

export const VersionFormView: React.FC<VersionFormProps> = ({ form, errors, onChange, onSubmit, onCancel, busy, today, submitLabel = 'Schedule version' }) => (
  <form onSubmit={onSubmit} className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4" aria-label="New plan version">
    <fieldset className="flex flex-col gap-2">
      <legend className="text-[13px] font-semibold text-text-primary">Prices, as typed amounts (saved as minor units)</legend>
      <p className="text-xs text-text-muted">Leave a price empty for a custom quote.</p>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {CURRENCIES.map((c) => (
          <div key={c} className="flex flex-col gap-2">
            <span className="font-mono text-xs font-semibold text-text-secondary">{c}</span>
            {INTERVALS.map((i) => (
              <Field key={i} id={`price-${c}-${i}`} label={i === 'monthly' ? 'Monthly' : 'Annual'} error={errors[`prices.${c}.${i}`]}>
                <input
                  id={`price-${c}-${i}`} inputMode="decimal" autoComplete="off" className={field} value={form.prices[c][i]}
                  onChange={(e) => onChange({ ...form, prices: { ...form.prices, [c]: { ...form.prices[c], [i]: e.target.value } } })}
                />
              </Field>
            ))}
          </div>
        ))}
      </div>
    </fieldset>

    <fieldset className="flex flex-col gap-2">
      <legend className="text-[13px] font-semibold text-text-primary">Limits</legend>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {LIMIT_KEYS.map((k) => (
          <div key={k} className="flex flex-col gap-1.5">
            <Field id={`limit-${k}`} label={LIMIT_LABELS[k]} error={errors[`limits.${k}`]}>
              <input
                id={`limit-${k}`} inputMode="numeric" autoComplete="off" className={field} disabled={form.limits[k].unlimited}
                value={form.limits[k].unlimited ? '' : form.limits[k].value} placeholder={form.limits[k].unlimited ? 'Unlimited' : ''}
                onChange={(e) => onChange({ ...form, limits: { ...form.limits, [k]: { ...form.limits[k], value: e.target.value } } })}
              />
            </Field>
            <label htmlFor={`limit-${k}-unlimited`} className="flex items-center gap-2 text-xs text-text-secondary">
              <input
                id={`limit-${k}-unlimited`} type="checkbox" checked={form.limits[k].unlimited}
                onChange={(e) => onChange({ ...form, limits: { ...form.limits, [k]: { ...form.limits[k], unlimited: e.target.checked } } })}
              />
              Unlimited
            </label>
          </div>
        ))}
      </div>
    </fieldset>

    <fieldset className="flex flex-col gap-2">
      <legend className="text-[13px] font-semibold text-text-primary">Entitlements</legend>
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {ENTITLEMENT_KEYS.map((k) => (
          <label key={k} htmlFor={`ent-${k}`} className="flex items-center gap-2 text-xs text-text-primary">
            <input id={`ent-${k}`} type="checkbox" checked={form.entitlements[k]} onChange={(e) => onChange({ ...form, entitlements: { ...form.entitlements, [k]: e.target.checked } })} />
            {ENTITLEMENT_LABELS[k]}
          </label>
        ))}
      </div>
    </fieldset>

    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
      <Field id="version-trial" label="Trial days" error={errors.trialDays}>
        <input id="version-trial" inputMode="numeric" className={field} value={form.trialDays} onChange={(e) => onChange({ ...form, trialDays: e.target.value })} />
      </Field>
      <Field id="version-effective" label="Effective from" error={errors.effectiveFrom} hint="Today or later. A version in force is never edited, only replaced by a newer one.">
        <input id="version-effective" type="date" min={today} className={field} value={form.effectiveFrom} onChange={(e) => onChange({ ...form, effectiveFrom: e.target.value })} />
      </Field>
    </div>

    <div className="flex gap-2">
      <button type="submit" disabled={busy} className={primary}>{busy ? 'Saving…' : submitLabel}</button>
      <button type="button" onClick={onCancel} className={secondary}>Cancel</button>
    </div>
  </form>
);
