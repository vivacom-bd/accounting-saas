/**
 * Bad-debt provisioning, live (plan 3c.10). The rates per aging bucket (editable, kept as typed percentages), a preview for an as-of
 * date, and the runs. The server reads the receivables, rounds once per bucket and works out the difference to post; this page shows
 * its figures and never computes one. A run that went stale before approval is refused by the server and shown here calmly.
 */
import React, { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { PageHeader } from '@/components/domain/PageHeader';
import { StatusPill } from '@/components/domain/StatusPill';
import { TxWorkflowCard } from '@/components/live/TxWorkflowCard';
import { BTN_SECONDARY, CARD } from '@/pages/Sales/invoice-view/shared';
import { useRouteCompany } from '@/data/session';
import { useProvisionActions, useProvisionRates, useTx, useTxActions, useTxList } from '@/data/transactions';
import { IntentSlot } from '@/api/intentSlot';
import { ApiError } from '@/api/errors';
import { toApiDate } from '@/api/adapters/dates';
import {
  BUCKETS, BUCKET_LABELS, blankRates, minor, ratesProblems, toBucketRows, toRatesRequest, toTxCan, txErrorText,
  type ApiProvisionPreview, type ApiProvisionRun, type BucketRow, type RatesForm,
} from '@/api/adapters/transactions';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { BTN_ACCENT, ErrorLine, LoadMore, LoadingLine, TableCard, inputClass } from '@/pages/Sales/live/parts';

const BucketTable: React.FC<{ rows: BucketRow[]; required: number; existing: number; difference: number; currency: string }> = ({ rows, required, existing, difference, currency }) => (
  <table className="w-full text-left text-[13px]" aria-label="Allowance by bucket">
    <thead>
      <tr className="border-b border-border text-[11px] font-semibold uppercase tracking-[0.04em] text-text-muted">
        <th className="px-3 py-2">Aging bucket</th>
        <th className="px-3 py-2 text-right">Owed</th>
        <th className="px-3 py-2 text-right">Rate</th>
        <th className="px-3 py-2 text-right">Required</th>
      </tr>
    </thead>
    <tbody className="divide-y divide-border">
      {rows.map((r) => (
        <tr key={r.bucket}>
          <td className="px-3 py-2">{BUCKET_LABELS[r.bucket as keyof typeof BUCKET_LABELS] ?? r.label}</td>
          <td className="px-3 py-2 text-right tabular-nums">{formatMoney(r.balance, currency)}</td>
          <td className="px-3 py-2 text-right tabular-nums">{r.rate_pct}%</td>
          <td className="px-3 py-2 text-right tabular-nums">{formatMoney(r.amount, currency)}</td>
        </tr>
      ))}
    </tbody>
    <tfoot>
      <tr className="border-t border-border font-medium"><td className="px-3 py-2" colSpan={3}>Allowance required</td><td className="px-3 py-2 text-right tabular-nums" data-testid="prov-required">{formatMoney(required, currency)}</td></tr>
      <tr><td className="px-3 py-2 text-text-secondary" colSpan={3}>Allowance already held</td><td className="px-3 py-2 text-right tabular-nums" data-testid="prov-held">{formatMoney(existing, currency)}</td></tr>
      <tr className="font-semibold"><td className="px-3 py-2" colSpan={3}>{difference >= 0 ? 'To add to the allowance' : 'To release from the allowance'}</td><td className="px-3 py-2 text-right tabular-nums" data-testid="prov-difference">{formatMoney(Math.abs(difference), currency)}</td></tr>
    </tfoot>
  </table>
);

const RatesCard: React.FC = () => {
  const rates = useProvisionRates();
  const actions = useProvisionActions();
  const slot = useRef(new IntentSlot()).current;
  const [form, setForm] = useState<RatesForm>(blankRates());
  const [errors, setErrors] = useState<Partial<Record<keyof RatesForm, string>>>({});
  const [problem, setProblem] = useState('');
  const [busy, setBusy] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const canEdit = rates.data?.can?.manage_rates === true;

  useEffect(() => {
    if (rates.data && !loaded) {
      const next = blankRates();
      for (const b of rates.data.buckets) if (b.rate_pct !== null) next[b.bucket as keyof RatesForm] = b.rate_pct;
      setForm(next);
      setLoaded(true);
    }
  }, [rates.data, loaded]);

  const save = async () => {
    const found = ratesProblems(form);
    setErrors(found);
    setProblem('');
    if (Object.keys(found).length > 0) return;
    const body = toRatesRequest(form);
    setBusy(true);
    try {
      await actions.saveRates(body, slot.for(JSON.stringify(body)));
      slot.clear();
      toast.success('Rates saved');
    } catch (e) {
      setProblem(txErrorText(e, 'The rates could not be saved.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className={cn(CARD, 'p-5')} aria-label="Rates">
      <h2 className="text-[13px] font-semibold text-text-primary">Rate for each aging bucket</h2>
      <p className="mt-1 text-xs text-text-muted">The share of what customers owe that you expect not to collect. Adjust them when you know something the history does not show.</p>
      {rates.isLoading ? <LoadingLine what="rates" /> : rates.error ? <ErrorLine error={rates.error} onRetry={rates.refetch} /> : (
        <form onSubmit={(e) => { e.preventDefault(); void save(); }} className="mt-4 flex flex-col gap-3">
          <div className="grid gap-3 sm:grid-cols-5">
            {BUCKETS.map((b) => (
              <div key={b} className="flex flex-col gap-1">
                <label htmlFor={`rate-${b}`} className="text-[11px] font-medium text-text-muted">{BUCKET_LABELS[b]}</label>
                <div className="flex items-center gap-1">
                  <input id={`rate-${b}`} inputMode="decimal" value={form[b]} disabled={!canEdit} onChange={(e) => setForm({ ...form, [b]: e.target.value })} className={cn(inputClass, 'w-full text-right tabular-nums')} />
                  <span className="text-xs text-text-muted">%</span>
                </div>
                {errors[b] && <span role="alert" className="text-[11px] text-danger">{errors[b]}</span>}
              </div>
            ))}
          </div>
          {!rates.data?.configured && <p className="text-xs text-text-secondary">No rates are set yet. Set one for every bucket before you prepare a run.</p>}
          {problem && <p role="alert" className="text-[13px] text-danger">{problem}</p>}
          {canEdit ? (
            <div className="flex justify-end"><button type="submit" disabled={busy} className={BTN_ACCENT}>{busy ? 'Saving…' : 'Save rates'}</button></div>
          ) : (
            <p className="text-xs text-text-muted">You can see the rates. Changing them needs the permission to manage provisioning.</p>
          )}
        </form>
      )}
    </section>
  );
};

const NewRun: React.FC = () => {
  const company = useRouteCompany();
  const navigate = useNavigate();
  const provisioning = useProvisionActions();
  const runs = useTxActions<ApiProvisionRun>('provision-runs');
  const previewSlot = useRef(new IntentSlot()).current;
  const createSlot = useRef(new IntentSlot()).current;
  const [asOf, setAsOf] = useState(toApiDate(new Date()));
  const [memo, setMemo] = useState('');
  const [preview, setPreview] = useState<ApiProvisionPreview | null>(null);
  const [shownFor, setShownFor] = useState('');
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState('');

  const show = async () => {
    setBusy(true);
    setProblem('');
    try {
      setPreview(await provisioning.preview(asOf, previewSlot.for(`${asOf}:${Date.now()}`)));
      previewSlot.clear();
      setShownFor(asOf);
    } catch (e) {
      setPreview(null);
      setProblem(txErrorText(e, 'The preview could not be made.'));
    } finally {
      setBusy(false);
    }
  };
  const prepare = async () => {
    const body = { as_of: asOf, ...(memo.trim() ? { memo: memo.trim() } : {}) };
    setBusy(true);
    setProblem('');
    try {
      const run = await runs.create(body, createSlot.for(JSON.stringify(body)));
      createSlot.clear();
      navigate(`/${company.slug}/accounting/provisioning/runs/${run.id}`);
    } catch (e) {
      setProblem(txErrorText(e, 'The run could not be prepared.'));
    } finally {
      setBusy(false);
    }
  };
  const rows = preview ? toBucketRows(preview.buckets) : [];
  const currency = preview?.required.currency ?? company.base_currency;

  return (
    <section className={cn(CARD, 'p-5')} aria-label="Prepare a run">
      <h2 className="text-[13px] font-semibold text-text-primary">Prepare a run</h2>
      <p className="mt-1 text-xs text-text-muted">A run sets the allowance to what the rates require on the date you choose. Only the difference is posted, once for each period.</p>
      <div className="mt-4 flex flex-wrap items-end gap-3">
        <label className="flex flex-col gap-1 text-[11px] font-medium text-text-muted">
          As of
          <input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} className={cn(inputClass, 'w-44')} />
        </label>
        <button type="button" onClick={() => void show()} disabled={busy || !asOf} className={BTN_SECONDARY}>{busy ? 'Working it out…' : 'Show what it would be'}</button>
      </div>
      {problem && <p role="alert" className="mt-3 text-[13px] text-danger">{problem}</p>}
      {preview && (
        <div className="mt-4 flex flex-col gap-3">
          <BucketTable rows={rows} required={minor(preview.required)} existing={minor(preview.existing)} difference={minor(preview.difference)} currency={currency} />
          {minor(preview.difference) === 0 ? (
            <p className="text-xs text-text-secondary">The allowance already equals what the rates require, so there is nothing to post for {formatDate(shownFor)}.</p>
          ) : (
            <div className="flex flex-wrap items-end gap-3">
              <label className="flex min-w-64 flex-1 flex-col gap-1 text-[11px] font-medium text-text-muted">
                Note (optional)
                <input value={memo} onChange={(e) => setMemo(e.target.value)} className={cn(inputClass, 'w-full')} maxLength={500} />
              </label>
              <button type="button" onClick={() => void prepare()} disabled={busy || shownFor !== asOf} className={BTN_ACCENT} title={shownFor !== asOf ? 'Show the preview for this date first' : undefined}>Prepare this run</button>
            </div>
          )}
        </div>
      )}
    </section>
  );
};

export const ProvisioningLive: React.FC = () => {
  const company = useRouteCompany();
  const list = useTxList<ApiProvisionRun>('provision-runs');
  const co = `/${company.slug}`;
  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="Bad-debt provisioning" subtitle="Keep an allowance for the customer debts you do not expect to collect, based on how late they are." />
      <RatesCard />
      <NewRun />
      <section className="flex flex-col gap-3" aria-label="Runs">
        <h2 className="text-[13px] font-semibold text-text-primary">Runs</h2>
        {list.isLoading ? <LoadingLine what="runs" /> : list.error ? <ErrorLine error={list.error} onRetry={list.refetch} /> : list.rows.length === 0 ? (
          <p className="text-[13px] text-text-muted">No runs yet.</p>
        ) : (
          <TableCard>
            <table className="w-full text-left text-[13px]">
              <thead>
                <tr className="border-b border-border text-[11px] font-semibold uppercase tracking-[0.04em] text-text-muted">
                  <th className="px-4 py-2.5">Number</th><th className="px-4 py-2.5">As of</th><th className="px-4 py-2.5 text-right">Required</th><th className="px-4 py-2.5 text-right">Posts</th><th className="px-4 py-2.5">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {list.rows.map((r) => (
                  <tr key={r.id} className="hover:bg-surface-neutral">
                    <td className="px-4 py-2.5"><Link to={`${co}/accounting/provisioning/runs/${r.id}`} className="font-medium text-accent hover:underline">{r.number ?? 'Draft'}</Link></td>
                    <td className="px-4 py-2.5 tabular-nums text-text-secondary">{formatDate(r.as_of)}</td>
                    <td className="px-4 py-2.5 text-right tabular-nums">{formatMoney(minor(r.required), r.required.currency)}</td>
                    <td className="px-4 py-2.5 text-right tabular-nums">{formatMoney(Math.abs(minor(r.difference)), r.difference.currency)} {minor(r.difference) >= 0 ? 'added' : 'released'}</td>
                    <td className="px-4 py-2.5"><StatusPill status={r.status} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
            <LoadMore hasMore={list.hasMore} loading={list.isLoadingMore} onClick={list.loadMore} />
          </TableCard>
        )}
      </section>
    </div>
  );
};

export const ProvisionRunViewLive: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const company = useRouteCompany();
  const co = `/${company.slug}`;
  const q = useTx<ApiProvisionRun>('provision-runs', id);
  const [stale, setStale] = useState(false);

  if (q.isLoading) return <LoadingLine what="run" />;
  const r = q.data;
  if (q.error || !r) {
    const missing = q.error instanceof ApiError && q.error.status === 404;
    return missing ? <p className="p-4 text-[13px] text-text-secondary">This run does not exist in this company.</p> : <ErrorLine error={q.error} onRetry={q.refetch} />;
  }
  const can = toTxCan(r.can);
  const currency = r.required.currency;
  const diff = minor(r.difference);

  return (
    <div className="flex flex-col gap-6 pb-20">
      <PageHeader
        breadcrumb={[{ label: 'Provisioning', to: `${co}/accounting/provisioning` }, { label: r.number ?? 'Run' }]}
        title={r.number ?? (r.status === 'draft' ? 'Draft provision run' : 'Provision run')}
        subtitle={`As of ${formatDate(r.as_of)}`}
        meta={<StatusPill status={r.status} />}
      />
      {stale && (
        <div role="status" className="rounded-lg border border-warning/30 bg-warning-soft px-4 py-3 text-[13px] text-warning" data-testid="stale-note">
          <p className="font-medium">This run is out of date.</p>
          <p className="mt-0.5 text-xs">The receivables or the allowance changed after this run was prepared, so its figures no longer match your books. Nothing was posted. Discard this run, then prepare a new one so it reads today’s figures.</p>
        </div>
      )}
      {r.status === 'reversed' && (
        <div className="flex items-center gap-2 rounded-lg border border-border bg-surface-neutral px-4 py-3 text-[13px] font-medium text-text-secondary">
          This run was reversed.
          {r.reversal_journal_id && <Link className="underline" to={`${co}/journals/${r.reversal_journal_id}`}>View the reversing entry</Link>}
        </div>
      )}
      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section className={cn(CARD, 'p-5')} aria-label="Run details">
          <BucketTable rows={toBucketRows(r.buckets)} required={minor(r.required)} existing={minor(r.existing)} difference={diff} currency={currency} />
          <p className="mt-4 text-xs text-text-secondary">
            {diff >= 0
              ? 'Posting raises the allowance: bad-debt expense is debited and the allowance for credit losses is credited.'
              : 'Posting releases part of the allowance: the allowance for credit losses is debited and bad-debt expense is credited.'}
            {' '}Approving checks again that the receivables and the allowance have not moved since this run was prepared.
          </p>
          {r.memo && <p className="mt-2 text-xs text-text-muted">{r.memo}</p>}
          {r.journal_id && <Link className="mt-3 inline-block text-xs text-accent hover:underline" to={`${co}/journals/${r.journal_id}`}>View the journal entry</Link>}
        </section>
        <aside className="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
          <TxWorkflowCard
            family="provision-runs"
            noun="provision run"
            title="Provision run"
            doc={{ id: r.id, number: r.number, status: r.status, version: r.version, created_by: r.created_by, submitted_by: r.submitted_by, can }}
            headline={{ label: diff >= 0 ? 'Added to the allowance' : 'Released from the allowance', amount: Math.abs(diff), currency }}
            signAmount={Math.abs(diff)}
            signCurrency={currency}
            reverseText="Reversing adds an equal and opposite entry, so the allowance goes back to what it was before this run."
            postText="The allowance is set to what the rates require."
            onReload={() => void q.refetch()}
            onRefused={(e) => {
              const isStale = e instanceof ApiError && e.code === 'provision_run_stale';
              setStale(isStale);
              return isStale;
            }}
            onChanged={() => setStale(false)}
            onDiscarded={() => navigate(`${co}/accounting/provisioning`)}
          />
        </aside>
      </div>
    </div>
  );
};
