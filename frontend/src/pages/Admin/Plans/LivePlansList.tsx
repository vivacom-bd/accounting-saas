/**
 * Live plans, versions and add-ons (10_SaaS_Admin §3.3, plan Task 22). A version in force is read-only; a change is a new
 * version with a date. Prices are typed as amounts and sent as integer minor units per currency. Stripe sync status, the
 * entitlement matrix preview and anything else the API does not provide are not shown.
 */
import React, { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { useAdminSession } from '@/data/adminSession';
import { archivePlan, createPlan, listAddons, listPlans, publishPlanVersion, upsertAddon } from '@/api/live/platform/catalogue';
import { createIntent } from '@/api/idempotency';
import { ApiError } from '@/api/errors';
import {
  ADDON_KINDS, CURRENCIES, addonFormFrom, buildAddonBody, buildVersionBody, emptyVersionForm, formFromVersion, formatPlanPrice, isVersionEffective,
  type AddonForm, type ApiAddon, type ApiPlan, type Fields, type VersionForm,
} from '@/api/adapters/platform/catalogue';
import { Field, FormError, LoadError, Loading, field, primary, secondary } from '@/components/live/form';
import { VersionCard, VersionFormView } from './PlansViews';

const utcToday = () => new Date().toISOString().slice(0, 10);
const serverFields = (e: unknown): Fields =>
  e instanceof ApiError && e.errors ? Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0] ?? ''])) : {};

const PlanBlock: React.FC<{ plan: ApiPlan; canManage: boolean }> = ({ plan, canManage }) => {
  const qc = useQueryClient();
  const today = utcToday();
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState<VersionForm>(() => (plan.current_version ? formFromVersion(plan.current_version, today) : emptyVersionForm(today)));
  const [errors, setErrors] = useState<Fields>({});
  const intent = useRef(createIntent());
  const now = new Date();

  const publish = useMutation({
    mutationFn: (body: Parameters<typeof publishPlanVersion>[1]) => publishPlanVersion(plan.id, body, intent.current),
    onSuccess: () => {
      toast.success('Version scheduled.');
      setOpen(false);
      void qc.invalidateQueries({ queryKey: ['admin', 'plans'] });
    },
    onError: (e) => setErrors(serverFields(e)),
  });
  const archive = useMutation({
    mutationFn: () => archivePlan(plan.id),
    onSuccess: () => {
      toast.success('Plan archived.');
      void qc.invalidateQueries({ queryKey: ['admin', 'plans'] });
    },
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const built = buildVersionBody(form, today);
    if (!built.ok) return setErrors(built.errors);
    setErrors({});
    publish.mutate(built.body);
  };

  const versions = [...(plan.current_version ? [plan.current_version] : []), ...plan.scheduled_versions];

  return (
    <article className="flex flex-col gap-3 rounded-lg border border-border bg-surface-neutral/30 p-4" aria-label={plan.name}>
      <header className="flex flex-wrap items-center gap-2">
        <h3 className="text-[15px] font-semibold text-text-primary">{plan.name}</h3>
        <span className="font-mono text-[11px] text-text-muted">{plan.code}</span>
        {!plan.is_active && <span className="rounded-[3px] bg-surface-neutral px-1.5 py-px text-[11px] text-text-muted">Archived</span>}
        <span className="ml-auto text-xs text-text-secondary">{plan.tenant_count} tenants</span>
        {canManage && plan.is_active && (
          <>
            <button type="button" className={secondary} onClick={() => setOpen((o) => !o)}>{open ? 'Close' : 'New version'}</button>
            <button
              type="button" className={secondary} disabled={archive.isPending}
              onClick={() => { if (window.confirm(`Archive ${plan.name}? New subscriptions will no longer be able to choose it.`)) archive.mutate(); }}
            >
              Archive
            </button>
          </>
        )}
      </header>
      {plan.description && <p className="text-xs text-text-muted">{plan.description}</p>}
      <FormError error={archive.error} />
      {versions.length === 0 && <p className="text-xs text-text-muted">No version is in force yet.</p>}
      {versions.map((v) => <VersionCard key={v.id} version={v} effective={isVersionEffective(v, now)} />)}
      {open && (
        <>
          <VersionFormView form={form} errors={errors} onChange={setForm} onSubmit={submit} onCancel={() => setOpen(false)} busy={publish.isPending} today={today} />
          <FormError error={publish.error && Object.keys(serverFields(publish.error)).length === 0 ? publish.error : null} />
        </>
      )}
    </article>
  );
};

const NewPlan: React.FC<{ onDone: () => void }> = ({ onDone }) => {
  const qc = useQueryClient();
  const today = utcToday();
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [form, setForm] = useState<VersionForm>(() => emptyVersionForm(today));
  const [errors, setErrors] = useState<Fields>({});
  const intent = useRef(createIntent());
  const create = useMutation({
    mutationFn: (body: Parameters<typeof createPlan>[0]) => createPlan(body, intent.current),
    onSuccess: () => {
      toast.success('Plan created.');
      void qc.invalidateQueries({ queryKey: ['admin', 'plans'] });
      onDone();
    },
    onError: (e) => setErrors(serverFields(e)),
  });
  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const own: Fields = {};
    if (!/^[a-z][a-z0-9_]{1,31}$/.test(code)) own.code = 'Use 2 to 32 lowercase letters, digits or underscores, starting with a letter.';
    if (name.trim() === '') own.name = 'Enter a name.';
    const built = buildVersionBody(form, today);
    const all = { ...own, ...(built.ok ? {} : built.errors) };
    if (Object.keys(all).length > 0 || !built.ok) return setErrors(all);
    setErrors({});
    create.mutate({ ...built.body, code, name: name.trim() });
  };
  return (
    <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
      <h3 className="text-[15px] font-semibold text-text-primary">New plan</h3>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field id="plan-code" label="Code" error={errors.code} hint="Cannot be changed later."><input id="plan-code" className={field} value={code} onChange={(e) => setCode(e.target.value)} /></Field>
        <Field id="plan-name" label="Name" error={errors.name}><input id="plan-name" className={field} value={name} onChange={(e) => setName(e.target.value)} /></Field>
      </div>
      <VersionFormView form={form} errors={errors} onChange={setForm} onSubmit={submit} onCancel={onDone} busy={create.isPending} today={today} submitLabel="Create plan" />
      <FormError error={create.error && Object.keys(serverFields(create.error)).length === 0 ? create.error : null} />
    </div>
  );
};

const AddonEditor: React.FC<{ addon?: ApiAddon; onDone: () => void }> = ({ addon, onDone }) => {
  const qc = useQueryClient();
  const [form, setForm] = useState<AddonForm>(() => addonFormFrom(addon));
  const [errors, setErrors] = useState<Fields>({});
  const intent = useRef(createIntent());
  const save = useMutation({
    mutationFn: ({ code, body }: { code: string; body: Parameters<typeof upsertAddon>[1] }) => upsertAddon(code, body, intent.current),
    onSuccess: () => {
      toast.success('Add-on saved.');
      void qc.invalidateQueries({ queryKey: ['admin', 'addons'] });
      onDone();
    },
    onError: (e) => setErrors(serverFields(e)),
  });
  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const built = buildAddonBody(form);
    if (!built.ok) return setErrors(built.errors);
    setErrors({});
    save.mutate({ code: form.code.trim(), body: built.body });
  };
  return (
    <form onSubmit={submit} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4" aria-label="Add-on">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
        <Field id="addon-code" label="Code" error={errors.code}><input id="addon-code" className={field} disabled={addon !== undefined} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} /></Field>
        <Field id="addon-name" label="Name" error={errors.name}><input id="addon-name" className={field} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
        <Field id="addon-kind" label="Kind" error={errors.kind}>
          <select id="addon-kind" className={field} value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>{ADDON_KINDS.map((k) => <option key={k} value={k}>{k}</option>)}</select>
        </Field>
        <Field id="addon-unit" label="Unit label"><input id="addon-unit" className={field} value={form.unitLabel} onChange={(e) => setForm({ ...form, unitLabel: e.target.value })} /></Field>
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {CURRENCIES.map((c) => (
          <Field key={c} id={`addon-price-${c}`} label={`Price in ${c}`} error={errors[`price.${c}`]}>
            <input id={`addon-price-${c}`} inputMode="decimal" className={field} value={form.prices[c]} onChange={(e) => setForm({ ...form, prices: { ...form.prices, [c]: e.target.value } })} />
          </Field>
        ))}
      </div>
      {errors.price && <p role="alert" className="text-xs text-danger">{errors.price}</p>}
      <FormError error={save.error && Object.keys(serverFields(save.error)).length === 0 ? save.error : null} />
      <div className="flex gap-2">
        <button type="submit" className={primary} disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save add-on'}</button>
        <button type="button" className={secondary} onClick={onDone}>Cancel</button>
      </div>
    </form>
  );
};

const Addons: React.FC<{ canManage: boolean }> = ({ canManage }) => {
  const q = useQuery({ queryKey: ['admin', 'addons'], queryFn: listAddons });
  const [editing, setEditing] = useState<ApiAddon | 'new' | null>(null);
  return (
    <section className="flex flex-col gap-3" aria-label="Add-ons">
      <div className="flex items-center gap-2">
        <h2 className="text-[15px] font-semibold text-text-primary">Add-ons</h2>
        {canManage && editing === null && <button type="button" className={`ml-auto ${secondary}`} onClick={() => setEditing('new')}>New add-on</button>}
      </div>
      {q.isPending && <Loading what="Loading add-ons" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && q.data.length === 0 && editing === null && <p className="text-xs text-text-muted">No add-ons yet.</p>}
      {q.data && q.data.length > 0 && (
        <ul className="divide-y divide-border/60 rounded-lg border border-border bg-surface text-xs">
          {q.data.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center gap-3 p-3">
              <span className="font-semibold text-text-primary">{a.name}</span>
              <span className="font-mono text-text-muted">{a.code}</span>
              <span className="text-text-secondary">{a.kind}</span>
              <span className="font-mono text-text-secondary">{CURRENCIES.filter((c) => a.price[c] !== undefined).map((c) => formatPlanPrice(a.price[c], c)).join(' · ')}</span>
              {canManage && <button type="button" className="ml-auto text-accent hover:underline cursor-pointer" onClick={() => setEditing(a)}>Edit</button>}
            </li>
          ))}
        </ul>
      )}
      {editing !== null && <AddonEditor key={editing === 'new' ? 'new' : editing.id} addon={editing === 'new' ? undefined : editing} onDone={() => setEditing(null)} />}
    </section>
  );
};

export const LivePlansList: React.FC = () => {
  const session = useAdminSession();
  const canManage = session.data?.can.includes('plans.manage') ?? false;
  const q = useQuery({ queryKey: ['admin', 'plans'], queryFn: listPlans });
  const [creating, setCreating] = useState(false);

  return (
    <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 text-text-primary">
      <div className="flex items-center gap-2 border-b border-border pb-2">
        <h1 className="text-xl font-semibold">Plans and pricing</h1>
        {canManage && !creating && <button type="button" className={`ml-auto ${primary}`} onClick={() => setCreating(true)}>New plan</button>}
      </div>
      {creating && <NewPlan onDone={() => setCreating(false)} />}
      {q.isPending && <Loading what="Loading plans" />}
      {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} />}
      {q.data?.map((p) => <PlanBlock key={p.id} plan={p} canManage={canManage} />)}
      {q.data && q.data.length === 0 && <p className="text-[13px] text-text-muted">No plans yet.</p>}
      <Addons canManage={canManage} />
    </div>
  );
};
