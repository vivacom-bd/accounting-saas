/** Presentational pieces of the live staff and security screens. No data fetching here. */
import React from 'react';
import { formatDateTime } from '@/lib/format';
import { approvalHeadline, canDecideApproval, staffProblemMessage, verifyOutcome, type ApiApproval, type ApiVerifyReport } from '@/api/adapters/platform/staff';

export const ApprovalRow: React.FC<{
  approval: ApiApproval;
  meId: string;
  can: string[];
  names: Record<string, string>;
  onApprove: (a: ApiApproval) => void;
  onReject: (a: ApiApproval) => void;
  busy: boolean;
}> = ({ approval: a, meId, can, names, onApprove, onReject, busy }) => {
  const mine = a.requested_by === meId;
  return (
    <li className="flex flex-col gap-2 p-4 text-xs sm:flex-row sm:items-center sm:justify-between">
      <div className="space-y-1">
        <div className="flex items-center gap-2">
          <span className="font-semibold text-text-primary">{approvalHeadline(a)}</span>
          <span className="rounded-[3px] bg-surface-neutral px-1.5 py-px text-[11px] font-medium capitalize text-text-secondary">{a.status}</span>
        </div>
        <p className="text-text-secondary">Reason: {a.reason}</p>
        <p className="font-mono text-[11px] text-text-muted">
          Requested by {names[a.requested_by] ?? 'a former staff member'} on {formatDateTime(a.created_at)}
          {a.approved_by && ` · decided by ${names[a.approved_by] ?? 'a former staff member'}`}
          {a.rejection_reason && ` · rejected: ${a.rejection_reason}`}
        </p>
      </div>
      <div className="flex items-center gap-2">
        {canDecideApproval(a, meId, can) ? (
          <>
            <button type="button" disabled={busy} onClick={() => onApprove(a)} className="h-8 rounded-md bg-accent px-3 text-[13px] font-medium text-white hover:bg-accent-hover cursor-pointer disabled:opacity-60">Approve</button>
            <button type="button" disabled={busy} onClick={() => onReject(a)} className="h-8 rounded-md border border-border-strong px-3 text-[13px] font-medium text-text-primary hover:bg-surface-neutral cursor-pointer disabled:opacity-60">Reject</button>
          </>
        ) : (
          a.status === 'pending' && mine && <span className="rounded border border-warning/30 bg-warning-soft px-2 py-1 text-[11px] text-warning">Waiting for another approver: you cannot decide your own request.</span>
        )}
      </div>
    </li>
  );
};

export const VerifyResult: React.FC<{ report: ApiVerifyReport }> = ({ report }) => {
  const o = verifyOutcome(report);
  return o.tone === 'ok' ? (
    <p role="status" className="rounded-md bg-success-soft px-3 py-2 text-[13px] text-success">{o.text}</p>
  ) : (
    <p role="alert" className="rounded-md bg-danger-soft px-3 py-2 text-[13px] font-medium text-danger">{o.text}</p>
  );
};

/** A refusal from a staff action, in words (last owner, self lockout, anything else). */
export const StaffProblem: React.FC<{ error: unknown }> = ({ error }) =>
  error ? <p role="alert" className="text-[13px] text-danger">{staffProblemMessage(error)}</p> : null;
