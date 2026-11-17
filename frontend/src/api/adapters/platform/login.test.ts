import { describe, it, expect } from 'vitest';
import { initialLogin, loginReducer, clearTwoFactorMode, readTwoFactorMode, rememberTwoFactorMode, type LoginState } from '@/api/adapters/platform/login';

const enrol = { secret: 'ABCD EFGH', otpauth_url: 'otpauth://totp/x', qr_svg: '<svg></svg>' };

describe('admin login state machine', () => {
  it('starts at the password step', () => {
    expect(initialLogin).toEqual({ step: 'password' });
  });

  it('password accepted: a confirmed device asks for a code', () => {
    expect(loginReducer(initialLogin, { type: 'password_ok', twoFactor: 'challenge' })).toEqual({ step: 'challenge' });
  });

  it('password accepted: a new account must enrol first', () => {
    expect(loginReducer(initialLogin, { type: 'password_ok', twoFactor: 'enrol' })).toEqual({ step: 'enrol', enrolment: null });
  });

  it('enrolment carries the secret and QR until confirmed', () => {
    const s = loginReducer({ step: 'enrol', enrolment: null }, { type: 'enrol_started', enrolment: enrol });
    expect(s).toEqual({ step: 'enrol', enrolment: enrol });
  });

  it('confirming returns the recovery codes, shown once, and drops the enrolment secret', () => {
    const s = loginReducer({ step: 'enrol', enrolment: enrol }, { type: 'confirmed', recoveryCodes: ['aaaa-1111', 'bbbb-2222'] });
    expect(s).toEqual({ step: 'recovery', recoveryCodes: ['aaaa-1111', 'bbbb-2222'] });
    expect(JSON.stringify(s)).not.toContain('ABCD');
  });

  it('acknowledging the codes finishes sign-in and forgets them for good', () => {
    const s = loginReducer({ step: 'recovery', recoveryCodes: ['aaaa-1111'] }, { type: 'acknowledged' });
    expect(s).toEqual({ step: 'done' });
    expect(JSON.stringify(s)).not.toContain('aaaa');
  });

  it('a challenge answered right is done', () => {
    expect(loginReducer({ step: 'challenge' }, { type: 'verified' })).toEqual({ step: 'done' });
  });

  it('ignores events that do not belong to the step (no skipping the code)', () => {
    const challenge: LoginState = { step: 'challenge' };
    expect(loginReducer(challenge, { type: 'acknowledged' })).toBe(challenge);
    expect(loginReducer(initialLogin, { type: 'verified' })).toBe(initialLogin);
    expect(loginReducer(initialLogin, { type: 'confirmed', recoveryCodes: ['x'] })).toBe(initialLogin);
  });

  it('remembers which second step is due so a reload of the page asks the right thing', () => {
    const store = new Map<string, string>();
    const storage = { setItem: (k: string, v: string) => void store.set(k, v), getItem: (k: string) => store.get(k) ?? null, removeItem: (k: string) => void store.delete(k) };
    expect(readTwoFactorMode(storage)).toBe('challenge');
    rememberTwoFactorMode('enrol', storage);
    expect(readTwoFactorMode(storage)).toBe('enrol');
    expect(readTwoFactorMode(storage)).toBe('enrol');
    clearTwoFactorMode(storage);
    expect(readTwoFactorMode(storage)).toBe('challenge');
  });
});
