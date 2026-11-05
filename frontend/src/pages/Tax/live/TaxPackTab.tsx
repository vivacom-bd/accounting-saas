import React, { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatDate } from '@/lib/format';
import { useAllTaxCodes } from '@/data/tax';
import { useTaxPack, useTaxPackActions } from '@/data/taxPack';
import type { PackSectionState, TaxPackState } from '@/api/live/taxPack';
import { ApiError } from '@/api/errors';
import { describeError } from '@/api/messages';
import { IntentSlot } from '@/api/intentSlot';
import { BTN_ACCENT, BTN_GHOST, ErrorLine, LoadingLine, TableCard, inputClass } from '@/pages/Sales/live/parts';

const CANCEL = 'h-8 rounded-md border border-border bg-surface px-3 text-[13px] font-medium hover:bg-surface-neutral';
const SAVE = 'h-8 rounded-md bg-accent px-3 text-[13px] font-medium text-white hover:bg-accent-hover disabled:opacity-50';
const SIDE_LABEL = { sales: 'sales', purchases: 'purchases', reverse_charge: 'reverse charge' } as const;
const GROUP_LABEL = { output: 'Output tax', input: 'Input tax', adjustments: 'Adjustments', credits: 'Credits' } as const;

function problemText(e: unknown): string {
  if (e instanceof ApiError && e.violations?.length) return e.violations.map((v) => v.message).join(' ');
  return describeError(e);
}

const bp = (n: number) => `${n / 100}%`;

/** Choose the tax codes that feed one section of the return. */
const SectionDialog: React.FC<{ section: PackSectionState; onClose: () => void }> = ({ section, onClose }) => {
  const codes = useAllTaxCodes();
  const actions = useTaxPackActions();
  const sides = section.source_kind === 'codes' ? (['sales', 'purchases'] as const) : (['reverse_charge'] as const);
  const [chosen, setChosen] = useState<Set<string>>(new Set(section.codes.map((c) => `${c.side}:${c.tax_code_id}`)));
  const [problem, setProblem] = useState('');
  const [busy, setBusy] = useState(false);
  const slot = useRef(new IntentSlot()).current;
  const toggle = (k: string) => setChosen((p) => { const n = new Set(p); if (n.has(k)) n.delete(k); else n.add(k); return n; });
  const wanted = (side: string) => (side === 'reverse_charge' ? ['reverse_charge'] : ['vat', 'gst', 'sales', 'compound', 'zero', 'exempt']);

  async function save() {
    const body = [...chosen].map((k) => { const [side, id] = k.split(':'); return { tax_code_id: id, side }; });
    setBusy(true);
    setProblem('');
    try {
      await actions.saveSection(section.key, body, slot.for(JSON.stringify([section.key, body])));
      slot.clear();
      toast.success('Mapping saved');
      onClose();
    } catch (e) {
      setProblem(problemText(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && !busy && onClose()}>
      <DialogContent className="max-h-[85vh] max-w-lg overflow-y-auto rounded-lg border border-border bg-surface-elevated p-6 shadow-popover">
        <DialogHeader>
          <DialogTitle className="text-base font-semibold text-text-primary">{section.label}</DialogTitle>
          <DialogDescription className="text-xs text-text-muted">Tick the tax codes whose totals belong in this line of the return. This changes the layout only, never the books.</DialogDescription>
        </DialogHeader>
        {sides.map((side) => (
          <fieldset key={side} className="rounded-md border border-border p-3">
            <legend className="px-1 text-xs font-medium text-text-secondary">{side === 'reverse_charge' ? 'Reverse-charge purchases' : side === 'sales' ? 'Sales' : 'Purchases'}</legend>
            <div className="flex flex-col gap-1">
              {codes.codes.filter((c) => c.is_active && wanted(side).includes(c.kind)).map((c) => (
                <label key={c.id} className="flex items-center gap-2 text-[13px]">
                  <input type="checkbox" checked={chosen.has(`${side}:${c.id}`)} onChange={() => toggle(`${side}:${c.id}`)} aria-label={`${c.code} on ${SIDE_LABEL[side]}`} />
                  <span className="font-semibold text-text-primary">{c.code}</span>
                  <span className="text-text-muted">{c.name}</span>
                </label>
              ))}
            </div>
          </fieldset>
        ))}
        {problem && <p role="alert" className="text-xs font-medium text-danger">{problem}</p>}
        <DialogFooter className="flex justify-end gap-2">
          <button type="button" className={CANCEL} onClick={onClose} disabled={busy}>Cancel</button>
          <button type="button" className={SAVE} onClick={() => void save()} disabled={busy}>Save mapping</button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};

const Settings: React.FC<{ applied: NonNullable<TaxPackState['applied']> }> = ({ applied }) => {
  const actions = useTaxPackActions();
  const [authority, setAuthority] = useState(applied.authority);
  const [due, setDue] = useState(applied.due_days_after_period === null ? '' : String(applied.due_days_after_period));
  const [mode, setMode] = useState(applied.reverse_charge_mode);
  const [problem, setProblem] = useState('');
  const [busy, setBusy] = useState(false);
  const slot = useRef(new IntentSlot()).current;
  async function save() {
    const body = { version: applied.version, authority, due_days_after_period: due.trim() === '' ? null : Number(due), reverse_charge_mode: mode };
    setBusy(true);
    setProblem('');
    try {
      await actions.saveSettings(body, slot.for(JSON.stringify(body)));
      slot.clear();
      toast.success('Settings saved');
    } catch (e) {
      setProblem(problemText(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <form className="grid grid-cols-1 gap-3 rounded-lg border border-border bg-surface p-4 md:grid-cols-3" onSubmit={(e) => { e.preventDefault(); void save(); }} data-testid="pack-settings">
      <label className="flex flex-col gap-1 text-xs font-medium text-text-secondary">Tax authority<input className={inputClass} value={authority} onChange={(e) => setAuthority(e.target.value)} aria-label="Tax authority" /></label>
      <label className="flex flex-col gap-1 text-xs font-medium text-text-secondary">Return due, days after the period ends<input className={inputClass} inputMode="numeric" value={due} onChange={(e) => setDue(e.target.value)} aria-label="Days after the period ends" /></label>
      <label className="flex flex-col gap-1 text-xs font-medium text-text-secondary">Reverse charge on the return
        <select className={inputClass} value={mode} onChange={(e) => setMode(e.target.value as typeof mode)} aria-label="Reverse charge on the return">
          <option value="net_non_creditable">Declare only the non-creditable part</option>
          <option value="gross">Declare output and input in full</option>
        </select>
        <span className="text-[11px] font-normal text-text-muted">The books record both in full either way. This only changes how the return shows them; the tax payable is the same.</span>
      </label>
      {problem && <p role="alert" className="text-xs font-medium text-danger md:col-span-3">{problem}</p>}
      <div className="md:col-span-3"><button type="submit" className={SAVE} disabled={busy}>Save settings</button></div>
    </form>
  );
};

export const TaxPackTab: React.FC = () => {
  const q = useTaxPack();
  const actions = useTaxPackActions();
  const [editing, setEditing] = useState<PackSectionState | null>(null);
  const [problem, setProblem] = useState('');
  const [busy, setBusy] = useState(false);
  const slot = useRef(new IntentSlot()).current;

  async function apply(key: string) {
    setBusy(true);
    setProblem('');
    try {
      const r = await actions.apply(key, slot.for(`apply-${key}`));
      slot.clear();
      const c = r.result?.created ?? {};
      toast.success(`Pack applied: ${c.tax_codes ?? 0} tax codes, ${c.sections ?? 0} return lines and ${c.accounts ?? 0} accounts added. Nothing existing was changed.`);
    } catch (e) {
      setProblem(problemText(e));
    } finally {
      setBusy(false);
    }
  }

  if (q.isLoading) return <LoadingLine what="the country pack" />;
  if (q.error) return <ErrorLine error={q.error} onRetry={() => void q.refetch()} />;
  const s = q.data;
  if (!s) return null;
  const a = s.applied;

  return (
    <div className="flex flex-col gap-4" data-testid="tax-pack">
      {!a ? (
        <div className="rounded-lg border border-border bg-surface p-4">
          <h3 className="text-sm font-semibold text-text-primary">Country pack</h3>
          <p className="mt-1 max-w-2xl text-[13px] text-text-muted">A pack adds a country’s tax codes with their rates, lays the tax return out in that country’s sections and brings its tax depreciation classes. It only adds: nothing you already have is changed or deleted, and you can apply it again safely.</p>
          <div className="mt-3 flex flex-wrap gap-2">
            {s.available.map((p) => (
              <button key={p.key} type="button" className={BTN_ACCENT} disabled={busy} onClick={() => void apply(p.key)}>Apply the {p.name} pack</button>
            ))}
          </div>
          {problem && <p role="alert" className="mt-2 text-xs font-medium text-danger">{problem}</p>}
        </div>
      ) : (
        <>
          <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border bg-surface p-4">
            <div>
              <h3 className="text-sm font-semibold text-text-primary">{a.name} pack, version {a.pack_version}</h3>
              <p className="mt-1 text-xs text-text-muted">Applied {formatDate(a.applied_at)}. Facts checked {a.checked_on ? formatDate(a.checked_on) : 'on an unknown date'}.</p>
              <ul className="mt-2 flex flex-col gap-0.5 text-xs text-text-secondary">
                {a.sources.map((src) => (<li key={src.id}>{src.label}{src.secondary && <span className="ml-2 rounded-[3px] bg-warning/15 px-1.5 py-0.5 text-[11px] text-text-primary">secondary source, verify against ITA Fourth Schedule</span>}</li>))}
              </ul>
              {a.metadata_notes.map((n) => (<p key={n} className="mt-1 text-[11px] text-text-muted">{n}</p>))}
            </div>
            {a.latest_version > a.pack_version && (
              <button type="button" className={BTN_GHOST} disabled={busy} onClick={() => void apply(a.pack_key)}>Add what is new in version {a.latest_version}</button>
            )}
            {a.latest_version === a.pack_version && (
              <button type="button" className={BTN_GHOST} disabled={busy} onClick={() => void apply(a.pack_key)} title="Adds anything missing; changes nothing that exists">Apply again</button>
            )}
          </div>
          {problem && <p role="alert" className="text-xs font-medium text-danger">{problem}</p>}
          <Settings key={a.version} applied={a} />

          <div>
            <h3 className="pb-2 text-sm font-semibold text-text-primary">Lines of the tax return</h3>
            <TableCard>
              <table className="w-full text-left text-[13px]" data-testid="pack-sections">
                <thead>
                  <tr className="border-b border-border bg-surface-neutral text-[11px] font-semibold uppercase tracking-[0.04em] text-text-muted"><th className="px-3 py-2">Line</th><th className="px-3 py-2">Where the figure comes from</th><th className="px-3 py-2 text-right">Actions</th></tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {s.sections.map((sec) => (
                    <tr key={sec.key} data-section={sec.key}>
                      <td className="px-3 py-2"><div className="font-semibold text-text-primary">{sec.label}</div><div className="text-[11px] text-text-muted">{GROUP_LABEL[sec.group]}</div></td>
                      <td className="px-3 py-2 text-text-secondary">
                        {sec.source_kind === 'manual' ? 'Entered by hand on the tax period' : sec.codes.length === 0 ? <span className="text-text-muted">No tax codes mapped</span> : sec.codes.map((c) => `${c.code} (${SIDE_LABEL[c.side]})`).join(', ')}
                      </td>
                      <td className="px-3 py-2"><div className="flex justify-end">{sec.source_kind !== 'manual' && <button type="button" className={BTN_GHOST} aria-label={`Map ${sec.label}`} onClick={() => setEditing(sec)}>Map tax codes</button>}</div></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </TableCard>
            <p className="mt-2 text-xs text-text-muted">Box numbers of the tax authority’s form are not mapped. They stay unmapped until checked against the form.</p>
          </div>

          <div>
            <h3 className="pb-2 text-sm font-semibold text-text-primary">Tax depreciation classes</h3>
            <TableCard>
              <table className="w-full text-left text-[13px]" data-testid="pack-depreciation">
                <thead><tr className="border-b border-border bg-surface-neutral text-[11px] font-semibold uppercase tracking-[0.04em] text-text-muted"><th className="px-3 py-2">Class</th><th className="px-3 py-2 text-right">First year</th><th className="px-3 py-2 text-right">Each year</th><th className="px-3 py-2">Source</th></tr></thead>
                <tbody className="divide-y divide-border">
                  {s.depreciation_classes.map((d) => (
                    <tr key={d.key}>
                      <td className="px-3 py-2 text-text-primary">{d.label}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{d.first_year_bp === null ? '—' : bp(d.first_year_bp)}</td>
                      <td className="px-3 py-2 text-right tabular-nums">{bp(d.annual_bp)}</td>
                      <td className="px-3 py-2 text-text-secondary">{a.sources.find((x) => x.id === d.source)?.label ?? d.source}{d.secondary && <span className="ml-2 text-[11px] text-text-muted">secondary source, verify</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </TableCard>
            <p className="mt-2 text-xs text-text-muted">Straight line on cost, per item. The tax schedule never posts to the books. Investment deduction is not modelled.</p>
          </div>
        </>
      )}
      {editing && <SectionDialog section={editing} onClose={() => setEditing(null)} />}
    </div>
  );
};
