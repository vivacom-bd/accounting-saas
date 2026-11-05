/**
 * The workflow card of a transaction document (fund transfer, write-off, recovery, provision run): submit, approve, return, discard and
 * reverse, with the signing dialog and the version-conflict dialog. The server decides what is allowed (`can`); this shows it in plain
 * words. One user action is one Idempotency intent, kept until it succeeds; signing goes through `useSignedRun` only.
 */
import React, { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { toast } from 'sonner';
import { CheckCircle, Clock, Pencil, RotateCcw, Send, Trash2 } from 'lucide-react';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SignDialog } from '@/components/domain/SignDialog';
import { ConflictDialog, type VersionConflict } from '@/components/domain/ConflictDialog';
import { BTN_SECONDARY, CARD } from '@/pages/Sales/invoice-view/shared';
import { inputClass } from '@/pages/Accounting/live/parts';
import { useLedgerStore } from '@/mock/store';
import { useMemberNames } from '@/data/approvals';
import { isSigningCancelled, useSignedRun } from '@/data/signing';
import { useTxActions } from '@/data/transactions';
import { IntentSlot } from '@/api/intentSlot';
import { ApiError } from '@/api/errors';
import { txErrorText, type TxCan, type TxFamily, type TxStatus } from '@/api/adapters/transactions';
import { cn } from '@/lib/utils';
import { formatMoney } from '@/lib/format';

export interface TxDocLike {
  id: string;
  number: string | null;
  status: TxStatus;
  version: number;
  created_by: string | null;
  submitted_by: string | null;
  can: TxCan;
}

type Pending = 'submit' | 'approve' | 'reverse';
type Dialog = null | 'return' | 'void' | 'reverse';

export interface TxWorkflowCardProps {
  family: TxFamily;
  /** "transfer", "write-off", "recovery", "provision run". */
  noun: string;
  /** Capitalised, for dialog titles. */
  title: string;
  doc: TxDocLike;
  /** Shown big at the top of the card. */
  headline: { label: string; amount: number; currency: string };
  /** Base-currency amount for the signing dialog. */
  signAmount: number;
  signCurrency: string;
  /** What reversing does, in the document's own words. */
  reverseText: string;
  /** What posting does, in the document's own words. */
  postText: string;
  /** Where to edit a draft; the Edit button is drawn only with it. */
  editTo?: string;
  /** Called with the server's answer after any transition. */
  onChanged?: (d: TxDocLike) => void;
  /** Called with the error of a transition that was refused, before it is shown. */
  onRefused?: (e: unknown) => void;
  /** Called after a draft is discarded. */
  onDiscarded?: () => void;
  /** Extra buttons while posted, before Reverse. */
  postedExtras?: React.ReactNode;
  /** Extra lines under the card. */
  children?: React.ReactNode;
  onReload: () => void;
}

export const TxWorkflowCard: React.FC<TxWorkflowCardProps> = ({
  family, noun, title, doc, headline, signAmount, signCurrency, reverseText, postText, editTo, onChanged, onRefused, onDiscarded, postedExtras, children, onReload,
}) => {
  const me = useLedgerStore((s) => s.currentUser);
  const names = useMemberNames();
  const actions = useTxActions<TxDocLike>(family);
  const { run, signProps } = useSignedRun();
  const [pending, setPending] = useState<Pending | null>(null);
  const [busy, setBusy] = useState(false);
  const [dialog, setDialog] = useState<Dialog>(null);
  const [text, setText] = useState('');
  const [reverseDate, setReverseDate] = useState('');
  const [problem, setProblem] = useState('');
  const [conflict, setConflict] = useState<VersionConflict | null>(null);
  const slots = useRef(new Map<string, IntentSlot>());
  const slotFor = (name: string) => {
    let s = slots.current.get(name);
    if (!s) slots.current.set(name, (s = new IntentSlot()));
    return s;
  };

  const maker = names.get(doc.submitted_by ?? doc.created_by ?? '');
  const isMine = doc.created_by === me.id || doc.submitted_by === me.id;
  const can = doc.can;
  const sig = `${doc.id}:${doc.version}`;

  const onError = (e: unknown) => {
    if (isSigningCancelled(e)) return;
    if (e instanceof ApiError && e.isVersionConflict) {
      setDialog(null);
      setConflict({ noun, serverVersion: e.serverVersion, localVersion: doc.version, onClose: () => setConflict(null), onReload: () => { setConflict(null); onReload(); } });
      return;
    }
    onRefused?.(e);
    const msg = txErrorText(e, 'That did not go through. Try again.');
    if (dialog) setProblem(msg);
    else toast.error(msg);
  };

  /** One user action = one intent, kept until it succeeds, signed through the dialog if the server asks. */
  async function perform(name: string, signature: string, sign: Pending | null, verb: 'submit' | 'approve' | 'return' | 'void' | 'reverse', body: Record<string, unknown>) {
    const slot = slotFor(name);
    const intent = slot.for(signature);
    setPending(sign);
    setBusy(true);
    setProblem('');
    try {
      const r = await run(() => actions.transition(doc.id, verb, body, intent));
      slot.clear();
      onChanged?.(r);
      return r;
    } catch (e) {
      onError(e);
      return undefined;
    } finally {
      setBusy(false);
    }
  }

  const doSubmit = async () => {
    const r = await perform('submit', sig, 'submit', 'submit', { version: doc.version });
    if (r) toast.success(r.status === 'posted' ? `Posted as ${r.number}` : 'Sent for approval');
  };
  const doApprove = async () => {
    const r = await perform('approve', sig, 'approve', 'approve', { version: doc.version });
    if (r) toast.success(r.status === 'posted' ? `Approved and posted as ${r.number}` : 'Your approval is recorded. Another step is still to come.');
  };
  const doReturn = async () => {
    const comment = text.trim();
    if (!comment) return;
    const r = await perform('return', `${sig}:${comment}`, null, 'return', { comment, version: doc.version });
    if (r) {
      setDialog(null);
      setText('');
      toast.success('Sent back to the maker as a draft');
    }
  };
  const doVoid = async () => {
    const reason = text.trim();
    const r = await perform('void', `${sig}:${reason}`, null, 'void', { reason, version: doc.version });
    if (r) {
      setDialog(null);
      setText('');
      toast.success('Draft discarded');
      onDiscarded?.();
    }
  };
  const doReverse = async () => {
    const memo = text.trim();
    if (!memo) return;
    const r = await perform('reverse', `${sig}:${memo}:${reverseDate}`, 'reverse', 'reverse', { memo, version: doc.version, ...(reverseDate ? { date: reverseDate } : {}) });
    if (r) {
      setDialog(null);
      setText('');
      setReverseDate('');
      toast.success(`${title} ${doc.number ?? ''} reversed.`);
    }
  };

  const sign =
    pending === 'reverse'
      ? { action: `Reverse ${noun}`, text: reverseText }
      : pending === 'submit'
        ? { action: 'Sign and post', text: `Nobody else needs to approve this ${noun} under your company rules, so it posts now with the next number. ${postText}` }
        : { action: 'Approve and post', text: `Approving posts this ${noun} to your books with the next number. ${postText} Mistakes are fixed later with a reversal.` };

  return (
    <>
      <section className={cn(CARD, 'flex flex-col gap-4 p-5')} aria-label="Status and actions">
        <div>
          <div className="text-xs text-text-muted">{headline.label}</div>
          <div className="mt-1 text-[22px] font-medium tabular-nums text-text-primary">{formatMoney(headline.amount, headline.currency)}</div>
          {maker && <div className="mt-1 text-xs text-text-muted">Prepared by {maker}</div>}
        </div>

        {doc.status === 'pending_approval' &&
          (can.approve ? (
            <div className="grid grid-cols-2 gap-2">
              {can.return && (
                <button type="button" onClick={() => { setProblem(''); setText(''); setDialog('return'); }} disabled={busy} className={cn(BTN_SECONDARY, 'hover:border-danger/50 hover:text-danger')}>
                  <RotateCcw className="size-4" /> Return
                </button>
              )}
              <button type="button" onClick={() => void doApprove()} disabled={busy} className="flex h-8 cursor-pointer items-center justify-center gap-1.5 rounded-md bg-success px-3 text-[13px] font-medium text-white transition hover:opacity-90 disabled:opacity-50">
                <CheckCircle className="size-4" /> Approve
              </button>
            </div>
          ) : (
            <div className="flex items-center gap-2 rounded-md border border-warning/30 bg-warning-soft px-3 py-2 text-[13px] font-medium text-warning">
              <Clock className="size-4 shrink-0" />
              {isMine ? 'Waiting for someone else to approve' : 'Waiting for approval'}
            </div>
          ))}

        {doc.status === 'draft' && (
          <div className="flex flex-col gap-2">
            {can.submit && (
              <button type="button" onClick={() => void doSubmit()} disabled={busy} className="flex h-8 cursor-pointer items-center justify-center gap-1.5 rounded-md bg-accent px-3 text-[13px] font-medium text-white transition hover:bg-accent-hover disabled:opacity-50">
                <Send className="size-4" /> Submit
              </button>
            )}
            <div className="grid grid-cols-2 gap-2">
              {can.update && editTo && (
                <Link to={editTo} className={BTN_SECONDARY}>
                  <Pencil className="size-4 text-text-muted" /> Edit draft
                </Link>
              )}
              {can.void && (
                <button type="button" onClick={() => { setProblem(''); setText(''); setDialog('void'); }} className={cn(BTN_SECONDARY, 'hover:border-danger/50 hover:text-danger')}>
                  <Trash2 className="size-4" /> Discard
                </button>
              )}
            </div>
          </div>
        )}

        {doc.status === 'pending_approval' && can.void && (
          <button type="button" onClick={() => { setProblem(''); setText(''); setDialog('void'); }} className={cn(BTN_SECONDARY, 'hover:border-danger/50 hover:text-danger')}>
            <Trash2 className="size-4" /> {can.approve ? 'Discard' : 'Withdraw'}
          </button>
        )}

        {doc.status === 'posted' && (
          <div className="flex flex-col gap-2">
            {postedExtras}
            {can.reverse && (
              <button type="button" onClick={() => { setProblem(''); setText(''); setReverseDate(''); setDialog('reverse'); }} className={cn(BTN_SECONDARY, 'border-danger/40 text-danger hover:border-danger/60 hover:bg-danger-soft')}>
                <RotateCcw className="size-4" /> Reverse
              </button>
            )}
          </div>
        )}
        {children}
      </section>

      <Dialog open={dialog === 'return'} onOpenChange={(o) => !o && setDialog(null)}>
        <DialogContent className="max-w-md rounded-lg border border-border bg-surface-elevated p-6 shadow-popover">
          <DialogHeader><DialogTitle className="text-base font-semibold text-text-primary">Send back for changes</DialogTitle></DialogHeader>
          <label htmlFor="tx-return" className="text-xs font-semibold text-text-primary">Why are you sending it back?</label>
          <textarea id="tx-return" autoFocus rows={3} value={text} onChange={(e) => setText(e.target.value)} className="w-full rounded-lg border border-border bg-surface p-2 text-xs text-text-primary focus:outline-none focus:ring-1 focus:ring-accent" />
          {problem && <p role="alert" className="text-xs text-danger">{problem}</p>}
          <DialogFooter className="mt-2 flex justify-end gap-2">
            <button type="button" onClick={() => setDialog(null)} className="h-9 rounded-md border border-border bg-surface px-3 text-xs hover:bg-surface-neutral">Cancel</button>
            <button type="button" onClick={() => void doReturn()} disabled={!text.trim() || busy} className="h-9 rounded-md bg-warning px-3 text-xs font-semibold text-white disabled:opacity-50">Send back</button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={dialog === 'void'} onOpenChange={(o) => !o && setDialog(null)}>
        <DialogContent className="max-w-md rounded-lg border border-border bg-surface-elevated p-6 shadow-popover">
          <DialogHeader><DialogTitle className="text-base font-semibold text-text-primary">Discard this {noun}</DialogTitle></DialogHeader>
          <p className="text-xs text-text-secondary">A discarded {noun} is marked void and kept for the record. It never reaches the books.</p>
          <input value={text} onChange={(e) => setText(e.target.value)} placeholder="Reason (optional)" aria-label="Reason" className={cn(inputClass, 'w-full')} />
          {problem && <p role="alert" className="text-xs text-danger">{problem}</p>}
          <DialogFooter className="mt-2 flex justify-end gap-2">
            <button type="button" onClick={() => setDialog(null)} className="h-9 rounded-md border border-border bg-surface px-3 text-xs hover:bg-surface-neutral">Keep it</button>
            <button type="button" onClick={() => void doVoid()} disabled={busy} className="h-9 rounded-md bg-danger px-3 text-xs font-semibold text-white disabled:opacity-50">Discard</button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={dialog === 'reverse'} onOpenChange={(o) => !o && setDialog(null)}>
        <DialogContent className="max-w-lg rounded-lg border border-border bg-surface-elevated p-6 shadow-popover">
          <DialogHeader><DialogTitle className="text-base font-semibold text-text-primary">Reverse {doc.number}</DialogTitle></DialogHeader>
          <div className="mt-2 flex flex-col gap-3 text-xs text-text-secondary">
            <div className="rounded-md border border-danger/30 bg-danger-soft p-3 text-danger">
              <p className="font-semibold">A posted {noun} cannot be edited</p>
              <p className="mt-1 text-[11px]">{reverseText} Both entries stay visible in your records.</p>
            </div>
            <label className="flex flex-col gap-1 font-semibold text-text-primary">
              <span>Why are you reversing it? <span className="text-danger">*</span></span>
              <input value={text} onChange={(e) => setText(e.target.value)} aria-label="Why are you reversing it?" className={cn(inputClass, 'w-full font-normal')} />
            </label>
            <label className="flex flex-col gap-1 font-semibold text-text-primary">
              Reversal date (optional)
              <input type="date" value={reverseDate} onChange={(e) => setReverseDate(e.target.value)} className={cn(inputClass, 'w-44 font-normal')} />
              <span className="text-[11px] font-normal text-text-muted">Left empty, the server picks the date.</span>
            </label>
            <p className="text-[11px] text-text-muted">You may be asked for your signing PIN.</p>
            {problem && <p role="alert" className="text-xs font-medium text-danger">{problem}</p>}
          </div>
          <DialogFooter className="mt-4 flex justify-end gap-2">
            <button type="button" onClick={() => setDialog(null)} className="h-8 rounded-md border border-border bg-surface px-3 text-[13px] font-medium hover:bg-surface-neutral">Cancel</button>
            <button type="button" disabled={!text.trim() || busy} onClick={() => void doReverse()} className="h-8 rounded-md bg-danger px-3 text-[13px] font-medium text-white hover:bg-danger/90 disabled:opacity-50">Reverse {noun}</button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <SignDialog
        {...signProps}
        documentTitle={`${title}: ${doc.number || 'Draft'}`}
        documentNumber={doc.number || 'Draft'}
        amount={signAmount}
        currency={signCurrency}
        makerName={maker}
        actionTitle={sign.action}
        consequenceText={sign.text}
      />
      <ConflictDialog conflict={conflict} />
    </>
  );
};
