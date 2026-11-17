/** Live two-factor enrolment: QR and key, the first code, then the recovery codes (shown once). */
import React, { useEffect, useReducer, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AuthLayout } from '@/pages/Auth/AuthLayout';
import { confirmEnrolment, startEnrolment } from '@/api/live/platform/session';
import { FormError, Loading } from '@/components/live/form';
import { clearTwoFactorMode, loginReducer } from '@/api/adapters/platform/login';
import { takeNextPath } from '@/api/adapters/security';
import { useRefreshAdminSession } from '@/data/adminSession';
import { EnrolPanel, RecoveryCodesPanel } from './AdminAuthViews';

export const LiveAdminEnrol: React.FC = () => {
  const [state, dispatch] = useReducer(loginReducer, { step: 'enrol', enrolment: null });
  const [code, setCode] = useState('');
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const started = useRef(false);
  const navigate = useNavigate();
  const refresh = useRefreshAdminSession();

  // Asking twice would replace the secret the first QR was drawn from, so ask once even under StrictMode.
  useEffect(() => {
    if (started.current) return;
    started.current = true;
    startEnrolment().then((enrolment) => dispatch({ type: 'enrol_started', enrolment }), setError);
  }, []);

  const confirm = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const recoveryCodes = await confirmEnrolment(code);
      dispatch({ type: 'confirmed', recoveryCodes });
      setCode('');
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  const finish = async () => {
    dispatch({ type: 'acknowledged' });
    clearTwoFactorMode();
    await refresh();
    navigate(takeNextPath() ?? '/admin', { replace: true });
  };

  return (
    <AuthLayout>
      {state.step === 'recovery' ? (
        <>
          <h1 className="text-[22px] font-semibold text-text-primary">Save your recovery codes</h1>
          <RecoveryCodesPanel codes={state.recoveryCodes} onDone={finish} />
        </>
      ) : (
        <>
          <h1 className="text-[22px] font-semibold text-text-primary">Set up two-factor sign-in</h1>
          <p className="mt-1 text-[13px] text-text-muted">Staff accounts need an authenticator app. Scan the code, then enter the 6-digit code it shows.</p>
          {state.step === 'enrol' && state.enrolment ? (
            <EnrolPanel enrolment={state.enrolment} code={code} onCode={setCode} onSubmit={confirm} busy={busy} error={error} />
          ) : (
            <div className="mt-6 flex flex-col gap-3">
              {error ? <FormError error={error} /> : <Loading what="Preparing your setup" />}
            </div>
          )}
        </>
      )}
    </AuthLayout>
  );
};
