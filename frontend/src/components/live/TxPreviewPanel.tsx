/**
 * "Show posting preview" for a transaction document: the journal lines the server would post and its verdict. Nothing is saved. The
 * figures are the server's; this only lays them out with account names.
 */
import React, { useRef, useState } from 'react';
import { CheckCircle2 } from 'lucide-react';
import { BTN_SECONDARY, CARD } from '@/pages/Sales/invoice-view/shared';
import { useAccounts } from '@/data/accounts';
import { useTxActions } from '@/data/transactions';
import { describeViolation } from '@/api/violations';
import { IntentSlot } from '@/api/intentSlot';
import { toDocPreview, type DocPreview } from '@/api/adapters/invoice';
import { txErrorText, type TxFamily } from '@/api/adapters/transactions';
import { formatMoney } from '@/lib/format';

export const PreviewTable: React.FC<{ preview: DocPreview; accountName: (id: string) => string }> = ({ preview, accountName }) => (
  <>
    <p className="mt-1 text-xs text-text-muted">Period: {preview.period?.name ? `${preview.period.name} (${String(preview.period.status ?? '').replace('_', ' ')})` : 'none found for this date'}</p>
    {preview.lines.length > 0 && (
      <table className="mt-3 w-full text-left text-xs">
        <thead>
          <tr className="border-b border-border text-[10px] font-semibold uppercase tracking-[0.04em] text-text-muted">
            <th className="px-2 py-1">Account</th>
            <th className="px-2 py-1">What it is</th>
            <th className="w-28 px-2 py-1 text-right">Debit</th>
            <th className="w-28 px-2 py-1 text-right">Credit</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {preview.lines.map((pl, i) => (
            <tr key={`${pl.line_no}-${i}`}>
              <td className="px-2 py-1.5">{accountName(pl.account_id)}</td>
              <td className="px-2 py-1.5 text-text-secondary">{pl.description}</td>
              <td className="px-2 py-1.5 text-right tabular-nums">{pl.debit > 0 ? formatMoney(pl.debit, preview.currency) : ''}</td>
              <td className="px-2 py-1.5 text-right tabular-nums">{pl.credit > 0 ? formatMoney(pl.credit, preview.currency) : ''}</td>
            </tr>
          ))}
          <tr className="font-semibold">
            <td className="px-2 py-1.5" colSpan={2}>Total</td>
            <td className="px-2 py-1.5 text-right tabular-nums">{formatMoney(preview.totalDebit, preview.currency)}</td>
            <td className="px-2 py-1.5 text-right tabular-nums">{formatMoney(preview.totalCredit, preview.currency)}</td>
          </tr>
        </tbody>
      </table>
    )}
    {preview.violations.length > 0 && (
      <ul className="mt-3 flex flex-col gap-1 text-xs text-danger">
        {preview.violations.map((v, i) => (
          <li key={i}>{describeViolation(v)}</li>
        ))}
      </ul>
    )}
  </>
);

export const TxPreviewPanel: React.FC<{ family: Exclude<TxFamily, 'provision-runs'>; id: string; version: number }> = ({ family, id, version }) => {
  const actions = useTxActions<{ id: string }>(family);
  const accounts = useAccounts();
  const slot = useRef(new IntentSlot()).current;
  const [preview, setPreview] = useState<DocPreview | null>(null);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState('');
  const accountName = (aid: string) => {
    const a = accounts.data.find((x) => x.id === aid);
    return a ? `${a.code} ${a.name}` : '';
  };
  const show = async () => {
    setBusy(true);
    setProblem('');
    try {
      const r = await actions.preview(id, slot.for(`${id}:${version}:${Date.now()}`));
      slot.clear();
      setPreview(toDocPreview(r));
    } catch (e) {
      setProblem(txErrorText(e, 'The preview could not be made. Try again.'));
    } finally {
      setBusy(false);
    }
  };
  return (
    <section aria-label="Posting preview" data-testid="posting-preview" className={`${CARD} p-5 text-[13px]`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-[13px] font-semibold text-text-primary">Posting preview</h2>
        {preview ? (
          preview.valid ? (
            <span className="flex items-center gap-1 text-xs font-medium text-success"><CheckCircle2 className="size-3.5" /> The server would accept this</span>
          ) : (
            <span className="text-xs font-medium text-danger">The server would refuse this</span>
          )
        ) : null}
        <button type="button" onClick={() => void show()} disabled={busy} className={BTN_SECONDARY}>
          {busy ? 'Working it out…' : preview ? 'Refresh the preview' : 'Show posting preview'}
        </button>
      </div>
      {!preview && !problem && <p className="mt-2 text-xs text-text-muted">See the journal lines this would make before you submit. Nothing is saved.</p>}
      {problem && <p role="alert" className="mt-2 text-xs text-danger">{problem}</p>}
      {preview && <PreviewTable preview={preview} accountName={accountName} />}
    </section>
  );
};
