/**
 * The staff sign-in as a small state machine (password -> challenge | enrol -> recovery codes -> done).
 * Recovery codes live in state only between "confirmed" and "acknowledged": they are shown once and never kept.
 */
export interface Enrolment {
  secret: string;
  otpauth_url: string;
  qr_svg: string;
}

export type LoginState =
  | { step: 'password' }
  | { step: 'challenge' }
  | { step: 'enrol'; enrolment: Enrolment | null }
  | { step: 'recovery'; recoveryCodes: string[] }
  | { step: 'done' };

export type LoginEvent =
  | { type: 'password_ok'; twoFactor: 'challenge' | 'enrol' }
  | { type: 'enrol_started'; enrolment: Enrolment }
  | { type: 'confirmed'; recoveryCodes: string[] }
  | { type: 'acknowledged' }
  | { type: 'verified' };

export const initialLogin: LoginState = { step: 'password' };

export function loginReducer(state: LoginState, event: LoginEvent): LoginState {
  switch (event.type) {
    case 'password_ok':
      if (state.step !== 'password') return state;
      return event.twoFactor === 'enrol' ? { step: 'enrol', enrolment: null } : { step: 'challenge' };
    case 'enrol_started':
      return state.step === 'enrol' ? { step: 'enrol', enrolment: event.enrolment } : state;
    case 'confirmed':
      return state.step === 'enrol' ? { step: 'recovery', recoveryCodes: event.recoveryCodes } : state;
    case 'acknowledged':
      return state.step === 'recovery' ? { step: 'done' } : state;
    case 'verified':
      return state.step === 'challenge' ? { step: 'done' } : state;
  }
}

const MODE_KEY = 'ledgerly.admin.twoFactor';

/** Sign-in says which second step is due; the two-factor page can be reloaded, so keep it for the browser session. */
export function rememberTwoFactorMode(mode: 'challenge' | 'enrol', storage: Pick<Storage, 'setItem'> = sessionStorage): void {
  try {
    storage.setItem(MODE_KEY, mode);
  } catch {
    /* private mode: the page falls back to the code challenge */
  }
}

/** Which second step is due; a missing or unreadable note means the code challenge. Does not clear it. */
export function readTwoFactorMode(storage: Pick<Storage, 'getItem'> = sessionStorage): 'challenge' | 'enrol' {
  try {
    return storage.getItem(MODE_KEY) === 'enrol' ? 'enrol' : 'challenge';
  } catch {
    return 'challenge';
  }
}

export function clearTwoFactorMode(storage: Pick<Storage, 'removeItem'> = sessionStorage): void {
  try {
    storage.removeItem(MODE_KEY);
  } catch {
    /* nothing to clear */
  }
}
