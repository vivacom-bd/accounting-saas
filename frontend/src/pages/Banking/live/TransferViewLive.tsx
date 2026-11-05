/**
 * Fund transfer, live (plan 3c.8): the document, where the money is (in transit is said plainly), the journals both legs made, the
 * posting preview for a draft, the workflow card, and "Record that it arrived" (a signed act by someone with `transfers.receive`).
 * Every figure is the server's.
 */
import React, { useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { ArrowRightLeft, Clock3, CheckCircle2 } from 'lucide-react';
import { PageHeader } from '@/components/domain/PageHeader';
import { StatusPill } from '@/components/domain/StatusPill';
import { SignDialog } from '@/components/domain/SignDialog';
import { MoneyInput } from '@/components/domain/MoneyInput';
import { ConflictDialog, type VersionConflict } from '@/components/domain/ConflictDialog';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { TxWorkflowCard } from '@/components/live/TxWorkflowCard';
import { TxPreviewPanel } from '@/components/live/TxPreviewPanel';
import { BTN_SECONDARY, CARD } from '@/pages/Sales/invoice-view/shared';
import { useRouteCompany } from '@/data/session';
import { useBankAccounts } from '@/data/banking';
import { useTx, useTxActions } from '@/data/transactions';
import { isSigningCancelled, useSignedRun } from '@/data/signing';
import { IntentSlot } from '@/api/intentSlot';
import { ApiError } from '@/api/errors';
import { toApiDate } from '@/api/adapters/dates';
import { rateForDisplay } from '@/api/adapters/money';
import { fxDifferenceText, legStateText, minor, receiveProblems, toReceiveRequest, toTxCan, txErrorText, type ApiFundTransfer, type ReceiveForm } from '@/api/adapters/transactions';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ErrorLine, LoadingLine, inputClass } from '@/pages/Sales/live/parts';

const Row: React.FC<{ label: string; children: React.ReactNode }> = ({ label, children }) => (
  <>
    <dt className="text-text-muted">{label}</dt>
    <dd className="tabular-nums text-text-primary">{children}</dd>
  </>
);

export const TransferViewLive: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const company = useRouteCompany();
  const q = useTx<ApiFundTransfer>('fund-transfers', id);
  const banks = useBankAccounts();
  const actions = useTxActions<ApiFundTransfer>('fund-transfers');
  const { run, signProps } = useSignedRun();
  const [receiving, setReceiving] = useState(false);
  const [form, setForm] = useState<ReceiveForm>({ receivedOn: '', amount: 0, rate: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState('');
  const [busy, setBusy] = useState(false);
  const [conflict, setConflict] = useState<VersionConflict | null>(null);
  const slot = useRef(new IntentSlot()).current;

  if (q.isLoading) return <LoadingLine what="transfer" />;
  const t = q.data;
  if (q.error || !t) {
    const missing = q.error instanceof ApiError && q.error.status === 404;
    return missing ? <p className="p-4 text-[13px] text-text-secondary">This transfer does not exist in this company.</p> : <ErrorLine error={q.error} onRetry={q.refetch} />;
  }

  const base = company.base_currency;
  const co = `/${company.slug}`;
  const bankName = (bid: string) => banks.data.find((b) => b.id === bid)?.name ?? 'Account';
  const leg = legStateText(t);
  const can = toTxCan(t.can);
  const crossing = t.from_amount.currency !== t.to_amount.currency;
  const diff = fxDifferenceText(t.fx_difference);
  const inTransit = t.status === 'posted' && t.leg_state === 'in_transit';
  const today = toApiDate(new Date());

  const openReceive = () => {
    setForm({ receivedOn: today >= (t.send_date ?? '') ? today : (t.send_date ?? today), amount: 0, rate: '' });
    setErrors({});
    setProblem('');
    setReceiving(true);
  };

  const receive = async () => {
    const found = receiveProblems(form, t.send_date ?? '');
    setErrors(found);
    if (Object.keys(found).length > 0) return;
    const body = toReceiveRequest(form, t.version);
    setBusy(true);
    setProblem('');
    try {
      const done = await run(() => actions.transition(t.id, 'receive', body, slot.for(JSON.stringify(body))));
      slot.clear();
      setReceiving(false);
      toast.success('Recorded as received');
      void q.refetch();
      return done;
    } catch (e) {
      if (isSigningCancelled(e)) return;
      if (e instanceof ApiError && e.isVersionConflict) {
        setReceiving(false);
        setConflict({ noun: 'transfer', serverVersion: e.serverVersion, localVersion: t.version, onClose: () => setConflict(null), onReload: () => { setConflict(null); void q.refetch(); } });
        return;
      }
      setProblem(txErrorText(e, 'That could not be recorded.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col gap-6 pb-20">
      <PageHeader
        breadcrumb={[{ label: 'Bank accounts', to: `${co}/banking` }, { label: 'Transfers', to: `${co}/banking/transfers` }, { label: t.number ?? 'Transfer' }]}
        title={t.number ?? (t.status === 'draft' ? 'Draft transfer' : 'Transfer')}
        subtitle={`${bankName(t.from_bank_account_id)} to ${bankName(t.to_bank_account_id)}`}
        meta={<StatusPill status={t.status} />}
      />

      <div className={cn('flex items-start gap-3 rounded-lg border px-4 py-3 text-[13px]', leg.tone === 'warn' ? 'border-warning/30 bg-warning-soft text-warning' : leg.tone === 'ok' ? 'border-success/30 bg-success-soft text-success' : 'border-border bg-surface-neutral text-text-secondary')} data-testid="leg-state">
        {leg.tone === 'warn' ? <Clock3 className="mt-0.5 size-4 shrink-0" /> : leg.tone === 'ok' ? <CheckCircle2 className="mt-0.5 size-4 shrink-0" /> : <ArrowRightLeft className="mt-0.5 size-4 shrink-0" />}
        <div>
          <div className="font-medium">{leg.label}</div>
          {inTransit && <div className="mt-0.5 text-xs">{formatMoney(minor(t.send_base), base)} is held in funds in transit since {formatDate(t.send_date)}.</div>}
        </div>
      </div>

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="flex flex-col gap-6">
          <section className={cn(CARD, 'p-6')} aria-label="Transfer details">
            <dl className="grid grid-cols-[180px_1fr] gap-x-4 gap-y-2 text-[13px]">
              <Row label="From">{bankName(t.from_bank_account_id)}</Row>
              <Row label="To">{bankName(t.to_bank_account_id)}</Row>
              <Row label="Sent">{formatDate(t.send_date)}</Row>
              <Row label="Amount sent">{formatMoney(minor(t.from_amount), t.from_amount.currency)}</Row>
              {minor(t.fee) > 0 && <Row label="Bank fee">{formatMoney(minor(t.fee), t.fee.currency)}</Row>}
              {t.from_amount.currency !== base && <Row label={`Rate when sent`}>{rateForDisplay(t.send_rate)}</Row>}
              <Row label={`Held in transit (${base})`}>{formatMoney(minor(t.send_base), base)}</Row>
              <Row label={t.leg_state === 'completed' ? 'Amount received' : 'Amount expected'}>{formatMoney(minor(t.to_amount), t.to_amount.currency)}</Row>
              {t.leg_state === 'completed' && (
                <>
                  <Row label="Received on">{formatDate(t.received_on)}</Row>
                  {t.to_amount.currency !== base && <Row label="Rate when received">{rateForDisplay(t.receive_rate)}</Row>}
                  <Row label={`Value received (${base})`}>{t.receive_base ? formatMoney(minor(t.receive_base), base) : '—'}</Row>
                  {(crossing || diff.kind !== 'none') && (
                    <Row label="Exchange difference">
                      <span className={diff.kind === 'gain' ? 'text-success' : diff.kind === 'loss' ? 'text-danger' : 'text-text-muted'} data-testid="fx-difference">
                        {diff.kind === 'gain' ? `Gain of ${formatMoney(diff.amount, base)}` : diff.kind === 'loss' ? `Loss of ${formatMoney(diff.amount, base)}` : 'None'}
                      </span>
                    </Row>
                  )}
                </>
              )}
              <Row label="Posting">{t.settle_same_day ? 'Both halves together, the same day' : 'Sent now, received when you record it'}</Row>
              {t.reference && <Row label="Reference">{t.reference}</Row>}
              {t.memo && <Row label="Note">{t.memo}</Row>}
            </dl>
            <div className="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-xs">
              {t.send_journal_id && <Link className="text-accent hover:underline" to={`${co}/journals/${t.send_journal_id}`}>Journal for the sending half</Link>}
              {t.receive_journal_id && <Link className="text-accent hover:underline" to={`${co}/journals/${t.receive_journal_id}`}>Journal for the receiving half</Link>}
              {t.send_reversal_journal_id && <Link className="text-accent hover:underline" to={`${co}/journals/${t.send_reversal_journal_id}`}>Reversal of the sending half</Link>}
              {t.receive_reversal_journal_id && <Link className="text-accent hover:underline" to={`${co}/journals/${t.receive_reversal_journal_id}`}>Reversal of the receiving half</Link>}
            </div>
          </section>

          {(t.status === 'draft' || t.status === 'pending_approval') && <TxPreviewPanel family="fund-transfers" id={t.id} version={t.version} />}
        </div>

        <aside className="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
          <TxWorkflowCard
            family="fund-transfers"
            noun="transfer"
            title="Transfer"
            doc={{ id: t.id, number: t.number, status: t.status, version: t.version, created_by: t.created_by, submitted_by: t.submitted_by, can }}
            headline={{ label: 'Amount sent', amount: minor(t.from_amount), currency: t.from_amount.currency }}
            signAmount={minor(t.send_base)}
            signCurrency={base}
            reverseText="Reversing adds equal and opposite entries for each half that was posted, so the money is back where it started."
            postText="The money leaves the sending account and waits in funds in transit until it is received."
            editTo={`${co}/banking/transfers/new?draft=${t.id}`}
            onReload={() => void q.refetch()}
            onDiscarded={() => navigate(`${co}/banking/transfers`)}
            postedExtras={
              inTransit && can.receive ? (
                <button type="button" onClick={openReceive} className="flex h-8 cursor-pointer items-center justify-center gap-1.5 rounded-md bg-accent px-3 text-[13px] font-medium text-white transition hover:bg-accent-hover">
                  Record that it arrived
                </button>
              ) : inTransit ? (
                <p className="text-xs text-text-muted">Someone with the right to record receipts will mark this as arrived.</p>
              ) : null
            }
          />
        </aside>
      </div>

      <Dialog open={receiving} onOpenChange={(o) => !o && setReceiving(false)}>
        <DialogContent className="max-w-md rounded-lg border border-border bg-surface-elevated p-6 shadow-popover">
          <DialogHeader><DialogTitle className="text-base font-semibold text-text-primary">Record that it arrived</DialogTitle></DialogHeader>
          <div className="flex flex-col gap-3 text-xs text-text-secondary">
            <label className="flex flex-col gap-1 font-semibold text-text-primary">
              Date it arrived
              <input type="date" value={form.receivedOn} onChange={(e) => setForm({ ...form, receivedOn: e.target.value })} className={cn(inputClass, 'w-44 font-normal')} />
              {errors.receivedOn && <span role="alert" className="font-normal text-danger">{errors.receivedOn}</span>}
            </label>
            <label className="flex flex-col gap-1 font-semibold text-text-primary">
              Amount received ({t.to_amount.currency})
              <MoneyInput value={form.amount || minor(t.to_amount)} onChange={(v) => setForm({ ...form, amount: v })} currency={t.to_amount.currency} allowNegative={false} size="sm" />
              <span className="font-normal text-text-muted">{crossing ? 'What the bank actually credited. Any difference from what was expected goes to exchange gain or loss.' : 'Change it only if the bank credited a different amount.'}</span>
            </label>
            {t.to_amount.currency !== base && (
              <label className="flex flex-col gap-1 font-semibold text-text-primary">
                Exchange rate (optional)
                <input inputMode="decimal" value={form.rate} onChange={(e) => setForm({ ...form, rate: e.target.value })} className={cn(inputClass, 'w-44 font-normal tabular-nums')} />
                <span className="font-normal text-text-muted">Leave empty to use the rate stored for that day.</span>
                {errors.rate && <span role="alert" className="font-normal text-danger">{errors.rate}</span>}
              </label>
            )}
            <p className="text-[11px] text-text-muted">You will be asked for your signing PIN.</p>
            {problem && <p role="alert" className="font-medium text-danger">{problem}</p>}
          </div>
          <DialogFooter className="mt-3 flex justify-end gap-2">
            <button type="button" onClick={() => setReceiving(false)} className={BTN_SECONDARY}>Cancel</button>
            <button type="button" disabled={busy} onClick={() => void receive()} className="h-8 rounded-md bg-accent px-3 text-[13px] font-medium text-white hover:bg-accent-hover disabled:opacity-50">Record receipt</button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <SignDialog
        {...signProps}
        documentTitle={`Transfer: ${t.number ?? 'Draft'}`}
        documentNumber={t.number ?? 'Draft'}
        amount={minor(t.send_base)}
        currency={base}
        actionTitle="Record receipt"
        consequenceText="This books the receiving half: the money moves out of funds in transit into the destination account. Any difference from what was sent is posted as exchange gain or loss."
      />
      <ConflictDialog conflict={conflict} />
    </div>
  );
};
