import React, { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { formatDate, formatDateTime } from '@/lib/format';
import { useRouteCompany } from '@/data/session';
import { useTaxPeriod, useTaxPeriodActions, useTaxPeriods } from '@/data/taxPeriods';
import type { ApiTaxPeriod } from '@/api/live/taxPeriods';
import { ApiError } from '@/api/errors';
import { describeError } from '@/api/messages';
import { IntentSlot } from '@/api/intentSlot';
import { localDateISO } from '@/api/adapters/onboarding';
import { fmt } from '@/pages/Reports/live/parts';
import { BTN_ACCENT, BTN_GHOST, ErrorLine, LoadingLine, TableCard, inputClass } from '@/pages/Sales/live/parts';
import { TaxPackSections, type PackReturn } from './TaxPackSections';

const CANCEL = 'h-8 rounded-md border border-border bg-surface px-3 text-[13px] font-medium hover:bg-surface-neutral';
const SAVE = 'h-8 rounded-md bg-accent px-3 text-[13px] font-medium text-white hover:bg-accent-hover disabled:opacity-50';

function problemText(e: unknown): string {
  if (e instanceof ApiError && e.violations?.length) return e.violations.map((v) => v.message).join(' ');
  return describeError(e);
}

type Money = { amount: number } | undefined;
const amount = (m: unknown): number => ((m as Money)?.amount ?? 0);
type Rows = { rows?: Array<{ tax_code: { code: string } | null; net: Money; tax: Money }>; totals?: { net: Money; tax: Money } };

/** What the return looks like in a period: the totals per tax code, and the country pack's sections when there is a pack. */
const ReturnBlock: React.FC<{ ret: Record<string, unknown>; currency: string; title: string }> = ({ ret, currency, title }) => {
  const money = fmt(currency);
  const pack = ret.pack as PackReturn | undefined;
  return (
    <div className="rounded-md border border-border p-3" data-testid={`return-${title.toLowerCase().replace(/\s+/g, '-')}`}>
      <div className="mb-2 text-[13px] font-semibold text-text-primary">{title}</div>
      {pack ? (
        <TaxPackSections pack={pack} currency={currency} />
      ) : (
        <table className="w-full text-[12px]">
          <tbody>
            {(['sales', 'purchases'] as const).map((side) => (
              <tr key={side} className="border-b border-border/60">
                <td className="py-1 text-text-secondary">{side === 'sales' ? 'Tax charged (output)' : 'Tax paid (input)'}</td>
                <td className="py-1 text-right tabular-nums">{money(amount((ret[side] as Rows | undefined)?.totals?.tax))}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <div className="mt-2 flex justify-between text-[13px] font-semibold">
        <span>Net tax payable</span>
        <span className="tabular-nums" data-testid="period-net-payable">{money(amount(ret.net_tax_payable))}</span>
      </div>
    </div>
  );
};

const Detail: React.FC<{ id: string; onClose: () => void; onFile: (p: ApiTaxPeriod) => void; onReopen: (p: ApiTaxPeriod) => void }> = ({ id, onClose, onFile, onReopen }) => {
  const company = useRouteCompany();
  const q = useTaxPeriod(id);
  const p = q.data;
  const money = fmt(company.base_currency);
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto rounded-lg border border-border bg-surface-elevated p-6 shadow-popover">
        <DialogHeader>
          <DialogTitle className="text-base font-semibold text-text-primary">{p ? `${p.authority}: ${formatDate(p.starts_on)} to ${formatDate(p.ends_on)}` : 'Tax period'}</DialogTitle>
          <DialogDescription className="text-xs text-text-muted">The return as the books stand now. A filed period also shows what was filed.</DialogDescription>
        </DialogHeader>
        {q.isLoading && <LoadingLine what="the period" />}
        {q.error && <ErrorLine error={q.error} onRetry={() => void q.refetch()} />}
        {p && (
          <div className="flex flex-col gap-3">
            {p.changed_since_filing && (
              <div role="status" className="rounded-md border border-warning/50 bg-warning/10 p-3 text-[13px]" data-testid="changed-since-filing">
                <div className="font-semibold text-text-primary">Changed since filing</div>
                <p className="mt-1 text-text-secondary">Documents dated in this period were posted or reversed after it was filed. Declare the difference in the next return, or reopen this period.</p>
                <table className="mt-2 w-full text-[12px]">
                  <thead><tr className="text-left text-text-muted"><th className="py-1 font-medium">Figure</th><th className="py-1 text-right font-medium">Filed</th><th className="py-1 text-right font-medium">Now</th><th className="py-1 text-right font-medium">Difference</th></tr></thead>
                  <tbody>
                    {(p.changes_since_filing ?? []).map((c) => (
                      <tr key={c.key} className="border-t border-border/60">
                        <td className="py-1">{c.label}</td>
                        <td className="py-1 text-right tabular-nums">{money(c.filed)}</td>
                        <td className="py-1 text-right tabular-nums">{money(c.current)}</td>
                        <td className="py-1 text-right font-semibold tabular-nums">{money(c.difference)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {p.status === 'filed' && p.filed_return && (
              <p className="text-xs text-text-muted">Filed {formatDateTime(p.filed_at)} with reference <span className="font-semibold text-text-primary">{p.authority_reference}</span>.</p>
            )}
            {p.return && <ReturnBlock ret={p.return} currency={company.base_currency} title="Return now" />}
            {p.status === 'filed' && p.filed_return && <ReturnBlock ret={p.filed_return} currency={company.base_currency} title="Return as filed" />}
            {p.reopen_reason && p.status === 'open' && <p className="text-xs text-text-muted">Reopened {formatDateTime(p.reopened_at)}: {p.reopen_reason}</p>}
          </div>
        )}
        <DialogFooter className="mt-2 flex justify-end gap-2">
          {p?.can.file && <button type="button" className={SAVE} onClick={() => onFile(p)}>File this period</button>}
          {p?.can.reopen && <button type="button" className={CANCEL} onClick={() => onReopen(p)}>Reopen</button>}
          <button type="button" className={CANCEL} onClick={onClose}>Close</button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};

const TextDialog: React.FC<{ title: string; description: string; label: string; cta: string; onClose: () => void; onSubmit: (text: string) => Promise<void> }> = ({ title, description, label, cta, onClose, onSubmit }) => {
  const [text, setText] = useState('');
  const [problem, setProblem] = useState('');
  const [busy, setBusy] = useState(false);
  return (
    <Dialog open onOpenChange={(o) => !o && !busy && onClose()}>
      <DialogContent className="max-w-md rounded-lg border border-border bg-surface-elevated p-6 shadow-popover">
        <DialogHeader>
          <DialogTitle className="text-base font-semibold text-text-primary">{title}</DialogTitle>
          <DialogDescription className="text-xs text-text-muted">{description}</DialogDescription>
        </DialogHeader>
        <form className="flex flex-col gap-3" onSubmit={(e) => { e.preventDefault(); setBusy(true); setProblem(''); onSubmit(text).catch((err) => setProblem(problemText(err))).finally(() => setBusy(false)); }}>
          <label className="flex flex-col gap-1 text-xs font-medium text-text-secondary">
            {label}
            <input value={text} onChange={(e) => setText(e.target.value)} className={inputClass} autoFocus aria-label={label} />
          </label>
          {problem && <p role="alert" className="text-xs font-medium text-danger">{problem}</p>}
          <DialogFooter className="flex justify-end gap-2">
            <button type="button" onClick={onClose} disabled={busy} className={CANCEL}>Cancel</button>
            <button type="submit" disabled={busy || !text.trim()} className={SAVE}>{cta}</button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};

export const TaxPeriods: React.FC = () => {
  const periods = useTaxPeriods();
  const actions = useTaxPeriodActions();
  const [open, setOpen] = useState<string | null>(null);
  const [filing, setFiling] = useState<ApiTaxPeriod | null>(null);
  const [reopening, setReopening] = useState<ApiTaxPeriod | null>(null);
  const [generating, setGenerating] = useState(false);
  const [from, setFrom] = useState(localDateISO().slice(0, 8) + '01');
  const [months, setMonths] = useState('12');
  const [authority, setAuthority] = useState('');
  const [problem, setProblem] = useState('');
  const slot = useRef(new IntentSlot()).current;

  async function generate() {
    setProblem('');
    const body = { from, months: Number(months), ...(authority.trim() ? { authority: authority.trim() } : {}) };
    try {
      const n = await actions.generate(body, slot.for(JSON.stringify(body)));
      slot.clear();
      toast.success(n === 0 ? 'Those months already exist' : `${n} tax period${n === 1 ? '' : 's'} created`);
      setGenerating(false);
    } catch (e) {
      setProblem(problemText(e));
    }
  }

  const list = periods.data ?? [];
  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center justify-between gap-3">
        <p className="max-w-2xl text-[13px] text-text-muted">Filing a period freezes a copy of its return. It does not close the ledger period: later postings are still allowed, and the period then shows what changed since filing.</p>
        <button type="button" className={BTN_ACCENT} onClick={() => { setProblem(''); setGenerating(true); }}>Create periods</button>
      </div>
      <TableCard>
        {periods.isLoading ? (
          <LoadingLine what="tax periods" />
        ) : periods.error ? (
          <ErrorLine error={periods.error} onRetry={() => void periods.refetch()} />
        ) : list.length === 0 ? (
          <div className="px-6 py-14 text-center">
            <h3 className="text-sm font-semibold text-text-primary">No tax periods yet</h3>
            <p className="mt-1 text-[13px] text-text-muted">Create calendar months to file against.</p>
          </div>
        ) : (
          <table className="w-full text-left text-[13px]" data-testid="tax-periods-table">
            <thead>
              <tr className="border-b border-border bg-surface-neutral text-[11px] font-semibold uppercase tracking-[0.04em] text-text-muted">
                <th className="px-3 py-2">Period</th><th className="px-3 py-2">Authority</th><th className="px-3 py-2">Due</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Reference</th><th className="px-3 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {list.map((p) => (
                <tr key={p.id} data-tax-period={p.starts_on}>
                  <td className="px-3 py-2 font-semibold text-text-primary">{formatDate(p.starts_on)} to {formatDate(p.ends_on)}</td>
                  <td className="px-3 py-2 text-text-secondary">{p.authority}</td>
                  <td className="px-3 py-2 text-text-secondary">{p.due_on ? formatDate(p.due_on) : '—'}</td>
                  <td className="px-3 py-2">
                    <span className={cn('rounded-[3px] px-1.5 py-0.5 text-[11px]', p.status === 'filed' ? 'bg-surface-neutral text-text-primary' : 'text-text-muted')}>{p.status === 'filed' ? 'Filed' : 'Open'}</span>
                    {p.changed_since_filing && <span className="ml-2 rounded-[3px] bg-warning/15 px-1.5 py-0.5 text-[11px] font-medium text-text-primary">Changed since filing</span>}
                  </td>
                  <td className="px-3 py-2 text-text-secondary">{p.authority_reference ?? '—'}</td>
                  <td className="px-3 py-2"><div className="flex justify-end"><button type="button" className={BTN_GHOST} aria-label={`Open ${p.starts_on}`} onClick={() => setOpen(p.id)}>Open</button></div></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </TableCard>

      {open && <Detail id={open} onClose={() => setOpen(null)} onFile={(p) => setFiling(p)} onReopen={(p) => setReopening(p)} />}
      {filing && (
        <TextDialog
          title="File this period" description="Enter the reference the tax authority gave you. A copy of the return as it stands now is frozen with it." label="Authority reference" cta="File"
          onClose={() => setFiling(null)}
          onSubmit={async (ref) => { await actions.file(filing, ref, new IntentSlot().for(`file-${filing.id}-${ref}`)); toast.success('Period filed'); setFiling(null); }}
        />
      )}
      {reopening && (
        <TextDialog
          title="Reopen this period" description="Someone other than the person who filed it must reopen it, and say why. The filed copy stays in the audit trail." label="Reason" cta="Reopen"
          onClose={() => setReopening(null)}
          onSubmit={async (reason) => { await actions.reopen(reopening, reason, new IntentSlot().for(`reopen-${reopening.id}-${reason}`)); toast.success('Period reopened'); setReopening(null); }}
        />
      )}
      <Dialog open={generating} onOpenChange={(o) => !o && setGenerating(false)}>
        <DialogContent className="max-w-md rounded-lg border border-border bg-surface-elevated p-6 shadow-popover">
          <DialogHeader>
            <DialogTitle className="text-base font-semibold text-text-primary">Create tax periods</DialogTitle>
            <DialogDescription className="text-xs text-text-muted">Calendar months from the month you choose. Months that already exist are left alone.</DialogDescription>
          </DialogHeader>
          <form className="grid grid-cols-2 gap-3" onSubmit={(e) => { e.preventDefault(); void generate(); }}>
            <label className="flex flex-col gap-1 text-xs font-medium text-text-secondary">First month<input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={inputClass} aria-label="First month" /></label>
            <label className="flex flex-col gap-1 text-xs font-medium text-text-secondary">Months<input value={months} onChange={(e) => setMonths(e.target.value)} className={inputClass} inputMode="numeric" aria-label="Months" /></label>
            <label className="col-span-2 flex flex-col gap-1 text-xs font-medium text-text-secondary">Tax authority (optional)<input value={authority} onChange={(e) => setAuthority(e.target.value)} className={inputClass} placeholder="Leave empty to use the country pack's authority" aria-label="Tax authority" /></label>
            {problem && <p role="alert" className="col-span-2 text-xs font-medium text-danger">{problem}</p>}
            <DialogFooter className="col-span-2 flex justify-end gap-2">
              <button type="button" className={CANCEL} onClick={() => setGenerating(false)}>Cancel</button>
              <button type="submit" className={SAVE}>Create</button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
};
