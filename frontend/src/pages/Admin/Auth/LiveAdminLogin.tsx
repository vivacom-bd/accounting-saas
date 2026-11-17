/** Live staff sign-in, step one: email and password. The answer says whether a code or an enrolment comes next. */
import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AuthLayout } from '@/pages/Auth/AuthLayout';
import { adminLogin } from '@/api/live/platform/session';
import { fieldError } from '@/api/messages';
import { Field, FormError, field, primary } from '@/components/live/form';
import { initialLogin, loginReducer, rememberTwoFactorMode } from '@/api/adapters/platform/login';

export const LiveAdminLogin: React.FC = () => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const navigate = useNavigate();

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const twoFactor = await adminLogin(email.trim(), password);
      const next = loginReducer(initialLogin, { type: 'password_ok', twoFactor });
      rememberTwoFactorMode(next.step === 'enrol' ? 'enrol' : 'challenge');
      navigate('/admin/two-factor');
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout>
      <h1 className="text-[22px] font-semibold text-text-primary">Platform admin sign in</h1>
      <p className="mt-1 text-[13px] text-text-muted">Vendor back office, staff only. A second step follows.</p>
      <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
        <Field id="admin-email" label="Email" error={fieldError(error, 'email')}>
          <input id="admin-email" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required className={field} />
        </Field>
        <Field id="admin-password" label="Password" error={fieldError(error, 'password')}>
          <input id="admin-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required className={field} />
        </Field>
        <FormError error={fieldError(error, 'email') || fieldError(error, 'password') ? null : error} />
        <button type="submit" disabled={busy} className={`mt-1 ${primary}`}>{busy ? 'Signing in…' : 'Sign in'}</button>
      </form>
      <p className="mt-4 text-[13px] text-text-muted">
        Not staff? <Link to="/login" className="text-accent hover:underline">Go to the customer sign in</Link>
      </p>
    </AuthLayout>
  );
};
