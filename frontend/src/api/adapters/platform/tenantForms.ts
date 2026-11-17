/**
 * Form state -> request bodies for the tenant dialogs, with the validation each one does before it calls the API.
 * Pure. Amounts are typed text turned into integer minor units with the currency's exponent (`parseMoneyText`),
 * never floats.
 */
import { parseMoneyText } from '@/lib/format';

export type FieldErrors<K extends string = string> = Partial<Record<K, string>>;

export const PLATFORM_CURRENCIES = ['USD', 'KES', 'EUR'] as const;

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function amountError(text: string, currency: string): string | undefined {
  if (text.trim() === '') return undefined;
  const v = parseMoneyText(text, currency);
  return v === null || v < 0 ? 'Enter an amount, for example 1500.00.' : undefined;
}

// --- Provision a tenant -------------------------------------------------------------------------------------------

export interface ProvisionForm {
  organisationName: string;
  country: string;
  ownerEmail: string;
  ownerName: string;
  planCode: string;
  billing: 'trial' | 'offline';
  trialDays: string;
  currency: string;
  interval: 'month' | 'year';
  customAmount: string;
  note: string;
}

export const emptyProvisionForm = (): ProvisionForm => ({
  organisationName: '', country: '', ownerEmail: '', ownerName: '', planCode: '', billing: 'trial', trialDays: '', currency: 'USD', interval: 'month', customAmount: '', note: '',
});

export type ProvisionErrors = FieldErrors<keyof ProvisionForm>;

export function validateProvision(f: ProvisionForm): ProvisionErrors {
  const e: ProvisionErrors = {};
  if (!f.organisationName.trim()) e.organisationName = 'Enter the organisation name.';
  if (!/^[A-Za-z]{2}$/.test(f.country.trim())) e.country = 'Use the two-letter country code, for example KE.';
  if (!EMAIL.test(f.ownerEmail.trim())) e.ownerEmail = 'Enter a valid email address.';
  if (!f.ownerName.trim()) e.ownerName = 'Enter the owner name.';
  if (!f.planCode.trim()) e.planCode = 'Choose a plan.';
  if (f.billing === 'trial' && f.trialDays.trim() !== '') {
    const d = Number(f.trialDays);
    if (!Number.isInteger(d) || d < 1 || d > 90) e.trialDays = 'Trial days must be a whole number from 1 to 90.';
  }
  if (f.billing === 'offline') {
    const a = amountError(f.customAmount, f.currency);
    if (a) e.customAmount = a;
  }
  return e;
}

export interface ProvisionBody {
  organisation_name: string;
  country: string;
  owner_email: string;
  owner_name: string;
  plan_code: string;
  billing: 'trial' | 'offline';
  trial_days?: number;
  currency?: string;
  interval?: 'month' | 'year';
  custom_amount?: number;
  note?: string;
}

export function toProvisionBody(f: ProvisionForm): ProvisionBody {
  const body: ProvisionBody = {
    organisation_name: f.organisationName.trim(),
    country: f.country.trim().toUpperCase(),
    owner_email: f.ownerEmail.trim(),
    owner_name: f.ownerName.trim(),
    plan_code: f.planCode.trim(),
    billing: f.billing,
  };
  if (f.billing === 'trial') {
    if (f.trialDays.trim() !== '') body.trial_days = Number(f.trialDays);
  } else {
    body.currency = f.currency;
    body.interval = f.interval;
    if (f.customAmount.trim() !== '') body.custom_amount = parseMoneyText(f.customAmount, f.currency) ?? 0;
  }
  if (f.note.trim()) body.note = f.note.trim();
  return body;
}

// --- Start a subscription -----------------------------------------------------------------------------------------

export interface SubscribeForm {
  planCode: string;
  interval: 'month' | 'year';
  currency: string;
  collection: 'stripe' | 'offline';
  customAmount: string;
  couponCode: string;
  reason: string;
}

export const emptySubscribeForm = (): SubscribeForm => ({ planCode: '', interval: 'month', currency: 'USD', collection: 'offline', customAmount: '', couponCode: '', reason: '' });

export function validateSubscribe(f: SubscribeForm, ctx: { stripeConfigured: boolean }): FieldErrors<keyof SubscribeForm> {
  const e: FieldErrors<keyof SubscribeForm> = {};
  if (!f.planCode.trim()) e.planCode = 'Choose a plan.';
  if (f.collection === 'stripe' && !ctx.stripeConfigured) e.collection = 'Stripe is not connected. Use offline invoicing.';
  const a = amountError(f.customAmount, f.currency);
  if (a) e.customAmount = a;
  return e;
}

export function toSubscribeBody(f: SubscribeForm): Record<string, unknown> {
  const body: Record<string, unknown> = { plan_code: f.planCode.trim(), interval: f.interval, currency: f.currency, collection: f.collection };
  if (f.customAmount.trim() !== '') body.custom_amount = parseMoneyText(f.customAmount, f.currency) ?? 0;
  if (f.couponCode.trim()) body.coupon_code = f.couponCode.trim();
  if (f.reason.trim()) body.reason = f.reason.trim();
  return body;
}

// --- Impersonate --------------------------------------------------------------------------------------------------

export interface ImpersonationForm {
  userId: string;
  mode: 'read' | 'write';
  reason: string;
  approvalId: string;
  ownerOverride: boolean;
}

export const emptyImpersonationForm = (): ImpersonationForm => ({ userId: '', mode: 'read', reason: '', approvalId: '', ownerOverride: false });

export function validateImpersonation(f: ImpersonationForm): FieldErrors<keyof ImpersonationForm> {
  const e: FieldErrors<keyof ImpersonationForm> = {};
  if (!f.userId) e.userId = 'Choose who to sign in as.';
  if (!f.reason.trim()) e.reason = 'Give a reason; it is recorded in the audit log.';
  if (f.mode === 'write' && !f.approvalId.trim()) e.approvalId = 'Write mode needs an approved request. Paste its approval id.';
  return e;
}

export interface ImpersonationBody {
  user_id: string;
  mode: 'read' | 'write';
  reason: string;
  approval_id?: string;
  owner_override?: boolean;
}

export function toImpersonationBody(f: ImpersonationForm): ImpersonationBody {
  const body: ImpersonationBody = { user_id: f.userId, mode: f.mode, reason: f.reason.trim() };
  if (f.mode === 'write' && f.approvalId.trim()) body.approval_id = f.approvalId.trim();
  if (f.ownerOverride) body.owner_override = true;
  return body;
}
