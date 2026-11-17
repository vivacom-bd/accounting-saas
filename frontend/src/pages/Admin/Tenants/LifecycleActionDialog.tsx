/**
 * Move a tenant between lifecycle states by hand. Targets come from `lifecycleTargets` (the backend canMove map minus
 * cancelled and deleted); closing a tenant is a separate "Request deletion" that creates a two-person approval.
 */
import React, { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { lifecycleTargets } from '@/lib/tenantLifecycle';
import { describeError } from '@/api/messages';
import { tenantsApi } from '@/api/live/platform/tenants';
import { Field, inputCls, btnPrimary, btnGhost, btnDanger, ErrorLine, LifecyclePill } from './tenantUi';

export const LifecycleActionForm: React.FC<{
  from: string;
  to: string;
  reason: string;
  error: string | null;
  busy: boolean;
  canRequestDeletion: boolean;
  onTo: (to: string) => void;
  onReason: (r: string) => void;
  onSubmit: () => void;
  onRequestDeletion: () => void;
}> = ({ from, to, reason, error, busy, canRequestDeletion, onTo, onReason, onSubmit, onRequestDeletion }) => {
  const targets = lifecycleTargets(from);
  const canClose = canRequestDeletion && from !== 'cancelled' && from !== 'deleted';
  return (
    <form
      className="flex flex-col gap-3"
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit();
      }}
    >
      <div className="text-[13px] flex items-center gap-2">
        Now <LifecyclePill status={from} />
      </div>
      {targets.length === 0 ? (
        <p className="text-[13px] text-text-secondary">No further changes can be made by hand from this state.</p>
      ) : (
        <>
          <Field label="Move to" htmlFor="lc-to">
            <select id="lc-to" className={inputCls} value={to} onChange={(e) => onTo(e.target.value)}>
              <option value="">Choose a state</option>
              {targets.map((t) => (
                <option key={t.to} value={t.to}>
                  {t.label}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Reason" htmlFor="lc-reason" hint="Recorded in the tenant timeline and the audit log.">
            <textarea id="lc-reason" rows={3} className={`${inputCls} h-auto py-1.5`} value={reason} onChange={(e) => onReason(e.target.value)} />
          </Field>
        </>
      )}
      <ErrorLine message={error} />
      <div className="flex items-center justify-between gap-2">
        <div>
          {canClose && (
            <button type="button" className={btnDanger} onClick={onRequestDeletion} disabled={busy} title="Closing a tenant needs a second approver">
              Request deletion
            </button>
          )}
        </div>
        {targets.length > 0 && (
          <button type="submit" className={btnPrimary} disabled={busy || !to || !reason.trim()}>
            {busy ? 'Saving…' : 'Apply'}
          </button>
        )}
      </div>
    </form>
  );
};

export const LifecycleActionDialog: React.FC<{ tenantId: string; tenantName: string; from: string; open: boolean; onOpenChange: (o: boolean) => void; canRequestDeletion: boolean }> = ({
  tenantId, tenantName, from, open, onOpenChange, canRequestDeletion,
}) => {
  const qc = useQueryClient();
  const [to, setTo] = useState('');
  const [reason, setReason] = useState('');
  const refresh = () => qc.invalidateQueries({ queryKey: ['admin', 'tenants'] });

  const move = useMutation({
    mutationFn: () => tenantsApi.changeLifecycle(tenantId, to, reason.trim()),
    onSuccess: () => {
      toast.success(`${tenantName} updated.`);
      void refresh();
      onOpenChange(false);
      setTo('');
      setReason('');
    },
  });
  const del = useMutation({
    mutationFn: () => tenantsApi.requestDeletion(tenantId, reason.trim()),
    onSuccess: () => {
      toast.success('Deletion requested. It waits for a second approver.');
      void refresh();
      onOpenChange(false);
      setReason('');
    },
  });
  const err = move.error ?? del.error;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Change status of {tenantName}</DialogTitle>
          <DialogDescription>Allowed moves only. Closing the tenant is a deletion request that another approver must decide.</DialogDescription>
        </DialogHeader>
        <LifecycleActionForm
          from={from}
          to={to}
          reason={reason}
          error={err ? describeError(err) : null}
          busy={move.isPending || del.isPending}
          canRequestDeletion={canRequestDeletion}
          onTo={setTo}
          onReason={setReason}
          onSubmit={() => move.mutate()}
          onRequestDeletion={() => (reason.trim() ? del.mutate() : toast.error('Give a reason for the deletion request first.'))}
        />
        <div className="flex justify-end">
          <button type="button" className={btnGhost} onClick={() => onOpenChange(false)}>
            Close
          </button>
        </div>
      </DialogContent>
    </Dialog>
  );
};
