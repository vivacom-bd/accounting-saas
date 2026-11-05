/**
 * Credit limit at invoice submit (plan 3c.12). In `warn` mode the invoice went ahead and the server's warning is shown calmly. In `refuse`
 * mode the invoice was not submitted; a person whose `can.override_credit_limit` is true (someone who did not make the invoice and holds
 * the permission) gets "Submit anyway" with a reason, in the manner of the bill match override. The maker is not offered it.
 */
import React, { useState } from 'react';
import { AlertTriangle, Info } from 'lucide-react';
import { ReasonDialog } from '@/pages/Procurement/parts';
import { BTN_SECONDARY } from '@/pages/Sales/invoice-view/shared';
import { cn } from '@/lib/utils';
import type { CreditWarning } from '@/api/adapters/transactions';

export const CreditLimitWarnings: React.FC<{ warnings: CreditWarning[]; onDismiss?: () => void }> = ({ warnings, onDismiss }) => {
  const limit = warnings.filter((w) => w.code === 'credit_limit_exceeded');
  if (limit.length === 0) return null;
  return (
    <div role="status" data-testid="credit-limit-warning" className="flex items-start gap-3 rounded-lg border border-warning/30 bg-warning-soft px-4 py-3 text-[13px] text-warning">
      <Info className="mt-0.5 size-4 shrink-0" />
      <div className="flex-1">
        <p className="font-medium">The invoice went ahead, and the customer is over their credit limit.</p>
        {limit.map((w, i) => <p key={i} className="mt-0.5 text-xs">{w.message}</p>)}
      </div>
      {onDismiss && <button type="button" onClick={onDismiss} className="text-xs underline">Dismiss</button>}
    </div>
  );
};

export const CreditLimitRefusal: React.FC<{
  message: string;
  canOverride: boolean;
  busy: boolean;
  onSubmitAnyway: (reason: string) => void;
}> = ({ message, canOverride, busy, onSubmitAnyway }) => {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  return (
    <div role="alert" data-testid="credit-limit-refusal" className="flex flex-col gap-2 rounded-lg border border-danger/30 bg-danger-soft p-4 text-xs text-danger">
      <div className="flex items-center gap-2 text-[13px] font-semibold">
        <AlertTriangle className="size-4 shrink-0" /> This customer is over their credit limit, so the invoice was not submitted.
      </div>
      <p className="font-medium">{message}</p>
      {canOverride ? (
        <div className="pt-1">
          <button type="button" disabled={busy} onClick={() => { setReason(''); setOpen(true); }} className={cn(BTN_SECONDARY, 'border-danger/40 text-danger hover:bg-surface')}>
            Submit anyway
          </button>
        </div>
      ) : (
        <p className="text-text-secondary">Someone else with the permission to override credit limits can pass this invoice with a reason, since you prepared it. You can also lower the invoice or raise the customer’s limit.</p>
      )}
      <ReasonDialog
        open={open}
        title="Submit this invoice anyway"
        intro="The customer is over their credit limit. Your reason is kept with the invoice and in the audit log. You may be asked for your signing PIN."
        label="Why is it right to let this invoice through?"
        confirm="Submit anyway"
        tone="danger"
        value={reason}
        onChange={setReason}
        busy={busy}
        onClose={() => setOpen(false)}
        onConfirm={() => {
          const r = reason.trim();
          setOpen(false);
          onSubmitAnyway(r);
        }}
      />
    </div>
  );
};
