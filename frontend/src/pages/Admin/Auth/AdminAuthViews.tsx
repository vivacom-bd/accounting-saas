/**
 * Presentational pieces of the live staff sign-in: the enrolment panel (QR + secret + first code) and the
 * recovery-codes panel. No network here, so they render the same in tests.
 */
import React, { useState } from 'react';
import { fieldError } from '@/api/messages';
import { Field, FormError, field, primary, secondary } from '@/components/live/form';
import { groupSecret, qrImageSrc } from '@/api/adapters/security';
import type { Enrolment } from '@/api/adapters/platform/login';

export const recoveryCodesText = (codes: string[]): string => `${codes.join('\n')}\n`;

export const EnrolPanel: React.FC<{
  enrolment: Enrolment;
  code: string;
  onCode: (v: string) => void;
  onSubmit: (e: React.FormEvent) => void;
  busy: boolean;
  error: unknown;
}> = ({ enrolment, code, onCode, onSubmit, busy, error }) => {
  // The QR is SVG markup from the API: shown through <img> as a data URI, never injected as HTML.
  const src = qrImageSrc(enrolment.qr_svg);
  return (
    <form onSubmit={onSubmit} className="mt-6 flex flex-col gap-4">
      {src ? (
        <img src={src} alt="QR code to add Ledgerly Platform to your authenticator app" width={176} height={176} className="self-center rounded-md border border-border bg-white p-2" />
      ) : (
        <p className="text-[13px] text-text-muted">The QR code could not be shown. Type the key below into your authenticator app instead.</p>
      )}
      <div className="flex flex-col gap-1">
        <span className="text-[13px] font-medium text-text-primary">Or type this key</span>
        <code className="rounded-md bg-surface-neutral px-3 py-2 text-[13px] tabular-nums tracking-wider text-text-primary break-all select-text">{groupSecret(enrolment.secret)}</code>
      </div>
      <Field id="enrol-code" label="6-digit code" error={fieldError(error, 'code')} hint="Enter the code your app shows now to finish setting up.">
        <input id="enrol-code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} autoFocus value={code} onChange={(e) => onCode(e.target.value.replace(/\D/g, ''))} required className={field} />
      </Field>
      <FormError error={fieldError(error, 'code') ? null : error} />
      <button type="submit" disabled={busy || code.length < 6} className={primary}>
        {busy ? 'Confirming…' : 'Confirm and continue'}
      </button>
    </form>
  );
};

export const RecoveryCodesPanel: React.FC<{ codes: string[]; onDone: () => void }> = ({ codes, onDone }) => {
  const [copied, setCopied] = useState(false);
  const [saved, setSaved] = useState(false);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(recoveryCodesText(codes));
      setCopied(true);
      setSaved(true);
    } catch {
      setCopied(false);
    }
  };
  const download = () => {
    const url = URL.createObjectURL(new Blob([recoveryCodesText(codes)], { type: 'text/plain' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'ledgerly-admin-recovery-codes.txt';
    a.click();
    URL.revokeObjectURL(url);
    setSaved(true);
  };

  return (
    <div className="mt-6 flex flex-col gap-4">
      <p className="text-[13px] text-text-muted">
        Each code works once if you lose your authenticator. These codes will not be shown again, so keep them somewhere safe before you continue.
      </p>
      <ul className="grid grid-cols-2 gap-2 rounded-md border border-border bg-surface-subtle p-3 select-text">
        {codes.map((c) => (
          <li key={c}>
            <code className="text-[13px] tabular-nums text-text-primary">{c}</code>
          </li>
        ))}
      </ul>
      <div className="flex gap-2">
        <button type="button" onClick={copy} className={secondary}>{copied ? 'Copied' : 'Copy codes'}</button>
        <button type="button" onClick={download} className={secondary}>Download codes</button>
      </div>
      <label className="flex items-center gap-2 text-[13px] text-text-secondary">
        <input type="checkbox" checked={saved} onChange={(e) => setSaved(e.target.checked)} />
        I have saved these recovery codes
      </label>
      <button type="button" disabled={!saved} onClick={onDone} className={primary}>Continue to the console</button>
    </div>
  );
};
