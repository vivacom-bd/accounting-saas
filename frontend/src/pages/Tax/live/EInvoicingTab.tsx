import React, { useRef, useState } from 'react';
import { toast } from 'sonner';
import { useEInvoicingSettings, useSaveEInvoicingSettings } from '@/data/einvoicing';
import type { EInvoicingSettings } from '@/api/live/einvoicing';
import { ApiError } from '@/api/errors';
import { describeError } from '@/api/messages';
import { IntentSlot } from '@/api/intentSlot';
import { ErrorLine, LoadingLine, inputClass } from '@/pages/Sales/live/parts';

const SAVE = 'h-8 rounded-md bg-accent px-3 text-[13px] font-medium text-white hover:bg-accent-hover disabled:opacity-50';

const Form: React.FC<{ s: EInvoicingSettings }> = ({ s }) => {
  const save = useSaveEInvoicingSettings();
  const [enabled, setEnabled] = useState(s.enabled);
  const [adapter, setAdapter] = useState(s.adapter ?? '');
  const [taxId, setTaxId] = useState(s.tax_id ?? '');
  const [branch, setBranch] = useState(s.branch ?? '');
  const [problem, setProblem] = useState('');
  const [busy, setBusy] = useState(false);
  const slot = useRef(new IntentSlot()).current;
  const none = s.adapters.length === 0;

  async function submit() {
    const body = { enabled, adapter: adapter || null, tax_id: taxId.trim() || null, branch: branch.trim() || null, version: s.version };
    setBusy(true);
    setProblem('');
    try {
      await save(body, slot.for(JSON.stringify(body)));
      slot.clear();
      toast.success(enabled ? 'E-invoicing is on' : 'E-invoicing is off');
    } catch (e) {
      setProblem(e instanceof ApiError && e.violations?.length ? e.violations.map((v) => v.message).join(' ') : describeError(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="flex max-w-2xl flex-col gap-3 rounded-lg border border-border bg-surface p-4" onSubmit={(e) => { e.preventDefault(); void submit(); }} data-testid="einvoicing-settings">
      <p className="text-[13px] text-text-muted">When on, each posted sales invoice and credit note is sent to the tax authority after it is posted. Posting never waits for the authority, and a failed send never undoes a posting. The authority’s own number is kept next to ours, never in place of it.</p>
      {none && (
        <p role="status" className="rounded-md border border-border bg-surface-neutral p-3 text-[13px] text-text-secondary" data-testid="einvoicing-none">
          No e-invoicing adapter is installed in this system, so e-invoicing cannot be turned on and nothing is sent to any tax authority. Kenya eTIMS is not available: it has not been built or tested against KRA.
        </p>
      )}
      <label className="flex items-center gap-2 text-[13px] text-text-primary">
        <input type="checkbox" checked={enabled} disabled={none && !s.enabled} onChange={(e) => setEnabled(e.target.checked)} aria-label="Send documents to the tax authority" />
        Send documents to the tax authority
      </label>
      <label className="flex flex-col gap-1 text-xs font-medium text-text-secondary">Adapter
        <select className={inputClass} value={adapter} onChange={(e) => setAdapter(e.target.value)} disabled={none} aria-label="Adapter">
          <option value="">{none ? 'None available' : 'Choose an adapter'}</option>
          {s.adapters.map((a) => (<option key={a.key} value={a.key}>{a.label}</option>))}
        </select>
      </label>
      <div className="grid grid-cols-2 gap-3">
        <label className="flex flex-col gap-1 text-xs font-medium text-text-secondary">Company tax id<input className={inputClass} value={taxId} onChange={(e) => setTaxId(e.target.value)} aria-label="Company tax id" /></label>
        <label className="flex flex-col gap-1 text-xs font-medium text-text-secondary">Branch (optional)<input className={inputClass} value={branch} onChange={(e) => setBranch(e.target.value)} aria-label="Branch" /></label>
      </div>
      {problem && <p role="alert" className="text-xs font-medium text-danger">{problem}</p>}
      <div><button type="submit" className={SAVE} disabled={busy || (none && !s.enabled)}>Save</button></div>
    </form>
  );
};

export const EInvoicingTab: React.FC = () => {
  const q = useEInvoicingSettings();
  if (q.isLoading) return <LoadingLine what="e-invoicing" />;
  if (q.error) return <ErrorLine error={q.error} onRetry={() => void q.refetch()} />;
  return q.data ? <Form key={q.data.version ?? -1} s={q.data} /> : null;
};
