/**
 * Impersonate a tenant user (plan Task 14, consent G4). Reason is required; write mode needs an approved request id;
 * the Owner override skips the tenant's support-access grant and is flagged in the audit. The returned URL opens in a new
 * tab. The endpoint exists on every build.
 */
import React, { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { describeError } from '@/api/messages';
import { tenantsApi } from '@/api/live/platform/tenants';
import { emptyImpersonationForm, toImpersonationBody, validateImpersonation, type FieldErrors, type ImpersonationForm } from '@/api/adapters/platform/tenantForms';
import { Field, inputCls, btnPrimary, btnGhost, ErrorLine } from './tenantUi';

export interface ImpersonationUser {
  id: string;
  name: string;
  email: string;
}

export const ImpersonateFields: React.FC<{
  form: ImpersonationForm;
  errors: FieldErrors<keyof ImpersonationForm>;
  users: ImpersonationUser[];
  canWrite: boolean;
  isOwner: boolean;
  onChange: (patch: Partial<ImpersonationForm>) => void;
}> = ({ form, errors, users, canWrite, isOwner, onChange }) => (
  <div className="flex flex-col gap-3">
    <Field label="Sign in as" htmlFor="im-user" error={errors.userId}>
      <select id="im-user" className={inputCls} value={form.userId} onChange={(e) => onChange({ userId: e.target.value })}>
        <option value="">Choose a person</option>
        {users.map((u) => (
          <option key={u.id} value={u.id}>
            {u.name} ({u.email})
          </option>
        ))}
      </select>
    </Field>
    {canWrite ? (
      <Field label="Mode" htmlFor="im-mode">
        <select id="im-mode" className={inputCls} value={form.mode} onChange={(e) => onChange({ mode: e.target.value as 'read' | 'write' })}>
          <option value="read">Read-only (default)</option>
          <option value="write">Write (needs an approved request)</option>
        </select>
      </Field>
    ) : (
      <p className="text-[12px] text-text-secondary">This session is read-only: nothing can be changed.</p>
    )}
    {canWrite && form.mode === 'write' && (
      <Field label="Approval id" htmlFor="im-approval" error={errors.approvalId} hint="The id of the approved impersonate-write request.">
        <input id="im-approval" className={inputCls} value={form.approvalId} onChange={(e) => onChange({ approvalId: e.target.value })} />
      </Field>
    )}
    <Field label="Reason" htmlFor="im-reason" error={errors.reason}>
      <textarea id="im-reason" rows={3} className={`${inputCls} h-auto py-1.5`} value={form.reason} onChange={(e) => onChange({ reason: e.target.value })} />
    </Field>
    {isOwner && (
      <label className="flex items-start gap-2 text-[12px] text-text-secondary">
        <input type="checkbox" className="mt-0.5" checked={form.ownerOverride} onChange={(e) => onChange({ ownerOverride: e.target.checked })} />
        <span>Owner override: act without the organisation&apos;s support-access grant. This is flagged in the audit log.</span>
      </label>
    )}
  </div>
);

export const ImpersonateDialog: React.FC<{ tenantId: string; tenantName: string; open: boolean; onOpenChange: (o: boolean) => void; canWrite: boolean; isOwner: boolean }> = ({
  tenantId, tenantName, open, onOpenChange, canWrite, isOwner,
}) => {
  const [form, setForm] = useState<ImpersonationForm>(emptyImpersonationForm);
  const [errors, setErrors] = useState<FieldErrors<keyof ImpersonationForm>>({});
  const users = useQuery({ queryKey: ['admin', 'tenants', tenantId, 'users'], queryFn: () => tenantsApi.tenantUsers(tenantId), enabled: open });
  const start = useMutation({
    mutationFn: () => tenantsApi.startImpersonation(tenantId, toImpersonationBody(form)),
    onSuccess: (r) => {
      window.open(r.url, '_blank', 'noopener,noreferrer');
      toast.success('Impersonation session opened in a new tab.');
      onOpenChange(false);
      setForm(emptyImpersonationForm());
    },
  });
  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const errs = validateImpersonation(form);
    setErrors(errs);
    if (Object.keys(errs).length === 0) start.mutate();
  };
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Impersonate in {tenantName}</DialogTitle>
          <DialogDescription>Sessions last at most 60 minutes. The tenant sees a banner and every action is audited.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="flex flex-col gap-3" noValidate>
          <ImpersonateFields
            form={form}
            errors={errors}
            users={(users.data ?? []).map((u) => ({ id: u.id, name: u.name, email: u.email }))}
            canWrite={canWrite}
            isOwner={isOwner}
            onChange={(p) => setForm((f) => ({ ...f, ...p }))}
          />
          <ErrorLine message={start.error ? describeError(start.error) : users.error ? describeError(users.error) : null} />
          <div className="flex justify-end gap-2">
            <button type="button" className={btnGhost} onClick={() => onOpenChange(false)}>
              Cancel
            </button>
            <button type="submit" className={btnPrimary} disabled={start.isPending}>
              {start.isPending ? 'Opening…' : 'Start session'}
            </button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
};
