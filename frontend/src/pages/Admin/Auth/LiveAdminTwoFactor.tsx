/** Live staff sign-in, step two: the authenticator code (or a recovery code), or the enrolment when none exists yet. */
import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AuthLayout } from '@/pages/Auth/AuthLayout';
import { verifyTwoFactor } from '@/api/live/platform/session';
import { fieldError } from '@/api/messages';
import { Field, FormError, field, primary } from '@/components/live/form';
import { clearTwoFactorMode, readTwoFactorMode } from '@/api/adapters/platform/login';
import { takeNextPath } from '@/api/adapters/security';
import { useRefreshAdminSession } from '@/data/adminSession';
import { LiveAdminEnrol } from './LiveAdminEnrol';

export const LiveAdminTwoFactor: React.FC = () => {
  const [mode] = useState(() => readTwoFactorMode());
  return mode === 'enrol' ? <LiveAdminEnrol /> : <Challenge />;
};

const Challenge: React.FC = () => {
  const [code, setCode] = useState('');
  const [recovery, setRecovery] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const navigate = useNavigate();
  const refresh = useRefreshAdminSession();

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await verifyTwoFactor(recovery ? { recoveryCode: code.trim() } : { code: code.trim() });
      clearTwoFactorMode();
      await refresh();
      navigate(takeNextPath() ?? '/admin', { replace: true });
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout>
      <h1 className="text-[22px] font-semibold text-text-primary">{recovery ? 'Enter a recovery code' : 'Two-factor verification'}</h1>
      <p className="mt-1 text-[13px] text-text-muted">
        {recovery ? 'Use one of the recovery codes you saved when you set up two-factor sign-in. Each works once.' : 'Enter the 6-digit code from your authenticator app.'}
      </p>
      <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
        <Field id="admin-code" label={recovery ? 'Recovery code' : '6-digit code'} error={fieldError(error, recovery ? 'recovery_code' : 'code')}>
          <input id="admin-code" inputMode={recovery ? 'text' : 'numeric'} autoComplete="one-time-code" autoFocus value={code} onChange={(e) => setCode(e.target.value)} required className={field} />
        </Field>
        <FormError error={fieldError(error, 'code') || fieldError(error, 'recovery_code') ? null : error} />
        <button type="submit" disabled={busy} className={`mt-1 ${primary}`}>{busy ? 'Verifying…' : 'Verify'}</button>
      </form>
      <div className="mt-6 flex items-center justify-between border-t border-border pt-4 text-[13px] text-text-muted">
        <Link to="/admin/login" className="hover:text-text-primary">Back to staff sign in</Link>
        <button type="button" onClick={() => { setRecovery(!recovery); setCode(''); setError(null); }} className="hover:text-accent cursor-pointer">
          {recovery ? 'Use an authenticator code' : 'Use a recovery code'}
        </button>
      </div>
    </AuthLayout>
  );
};
