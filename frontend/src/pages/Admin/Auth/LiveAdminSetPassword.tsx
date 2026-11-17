/** Staff invitation landing: /admin/set-password?token=... sets the first password, then on to sign in. */
import React, { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { AuthLayout } from '@/pages/Auth/AuthLayout';
import { setAdminPassword } from '@/api/live/platform/session';
import { fieldError } from '@/api/messages';
import { Field, FormError, field, primary } from '@/components/live/form';

export const LiveAdminSetPassword: React.FC = () => {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const navigate = useNavigate();
  const mismatch = confirm !== '' && confirm !== password;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (password !== confirm) return;
    setBusy(true);
    setError(null);
    try {
      await setAdminPassword(token, password);
      toast.success('Password set. Sign in to continue.');
      navigate('/admin/login', { replace: true });
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  if (!token) {
    return (
      <AuthLayout>
        <h1 className="text-[22px] font-semibold text-text-primary">This link is not complete</h1>
        <p className="mt-2 text-[13px] text-text-muted">Open the invitation link from your email again, or ask an owner to send a new one.</p>
        <Link to="/admin/login" className="mt-6 inline-block text-[13px] text-accent hover:underline">Back to staff sign in</Link>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout>
      <h1 className="text-[22px] font-semibold text-text-primary">Choose your password</h1>
      <p className="mt-1 text-[13px] text-text-muted">You will set up two-factor sign-in on your first visit.</p>
      <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
        <Field id="admin-new-password" label="New password" error={fieldError(error, 'password')}>
          <input id="admin-new-password" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} required className={field} />
        </Field>
        <Field id="admin-confirm-password" label="Confirm password" error={mismatch ? 'The two passwords do not match.' : undefined}>
          <input id="admin-confirm-password" type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} required className={field} />
        </Field>
        <FormError error={fieldError(error, 'password') ? null : error} />
        <button type="submit" disabled={busy || mismatch} className={`mt-1 ${primary}`}>{busy ? 'Saving…' : 'Set password'}</button>
      </form>
    </AuthLayout>
  );
};
