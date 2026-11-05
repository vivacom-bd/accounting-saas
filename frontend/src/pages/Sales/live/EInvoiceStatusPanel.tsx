import React, { useRef, useState } from 'react';
import { toast } from 'sonner';
import { useEInvoiceRetry, useEInvoiceStatus } from '@/data/einvoicing';
import type { EInvoiceStatusName } from '@/api/live/einvoicing';
import { ApiError } from '@/api/errors';
import { describeError } from '@/api/messages';
import { IntentSlot } from '@/api/intentSlot';
import { formatDateTime } from '@/lib/format';
import { CARD } from '@/pages/Sales/invoice-view/shared';
import { BTN_GHOST } from './parts';

const WORDS: Record<Exclude<EInvoiceStatusName, 'not_required'>, { label: string; help: string }> = {
  pending: { label: 'Queued', help: 'Waiting to be sent to the tax authority. The document is already posted and numbered.' },
  submitting: { label: 'Sending', help: 'Being sent to the tax authority now.' },
  accepted: { label: 'Accepted', help: 'The tax authority accepted this document.' },
  rejected: { label: 'Rejected', help: 'The tax authority refused this document. It will not be sent again. Credit it and issue a corrected one.' },
  failed_retryable: { label: 'Not sent yet', help: 'The last attempt failed. It is tried again automatically; you can also retry now.' },
  unknown: { label: 'No answer', help: 'The tax authority did not answer, so it may or may not have the document. A retry asks it first and sends only if it has nothing.' },
  voided_by_credit: { label: 'Cancelled by a credit note', help: 'Accepted earlier, then cancelled in full by an accepted credit note.' },
};

/** Where a posted sales document stands with the tax authority. Shown only when e-invoicing queued it; the authority's number is separate from ours. */
export const EInvoiceStatusPanel: React.FC<{ documentId: string; posted: boolean }> = ({ documentId, posted }) => {
  const q = useEInvoiceStatus(documentId, posted);
  const retry = useEInvoiceRetry(documentId);
  const slot = useRef(new IntentSlot()).current;
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState('');
  const s = q.data;
  if (!s || s.status === 'not_required') return null;
  const w = WORDS[s.status];

  async function run() {
    setBusy(true);
    setProblem('');
    try {
      await retry(slot.for(`retry-${documentId}`));
      slot.clear();
      toast.success('Retry requested');
    } catch (e) {
      setProblem(e instanceof ApiError && e.violations?.length ? e.violations.map((v) => v.message).join(' ') : describeError(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className={CARD} data-testid="einvoice-status" aria-label="Tax authority status">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-sm font-semibold text-text-primary">Tax authority</h3>
          <p className="mt-1 text-[13px] text-text-primary"><span data-testid="einvoice-state">{w.label}</span>{s.authority_number && <> · authority number <span className="font-semibold" data-testid="einvoice-number">{s.authority_number}</span></>}</p>
          <p className="mt-1 text-xs text-text-muted">{w.help}</p>
          {s.authority_timestamp && <p className="text-xs text-text-muted">Accepted {formatDateTime(s.authority_timestamp)}{s.device_id ? ` on device ${s.device_id}` : ''}.</p>}
          {s.last_error_message && s.status !== 'accepted' && <p className="mt-1 text-xs text-text-secondary">Last problem: {s.last_error_message}</p>}
          {s.dead_lettered && <p className="mt-1 text-xs font-medium text-danger">Automatic retries have stopped after {s.attempts} attempts.</p>}
          {!s.dead_lettered && s.next_attempt_at && s.status !== 'accepted' && <p className="mt-1 text-xs text-text-muted">Next attempt {formatDateTime(s.next_attempt_at)}.</p>}
        </div>
        {s.can_retry && <button type="button" className={BTN_GHOST} disabled={busy} onClick={() => void run()}>Retry now</button>}
      </div>
      {problem && <p role="alert" className="mt-2 text-xs font-medium text-danger">{problem}</p>}
    </section>
  );
};
