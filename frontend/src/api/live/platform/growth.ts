/** Live trials and growth (plan Task 16): funnel, current trials, quoted conversion and the nudges switch. */
import { adminHttp } from '../../adminHttp';
import { createIntent, type Intent } from '../../idempotency';

export interface Funnel {
  signups: number;
  created_company: number;
  posted_first_journal: number;
  converted: number;
  conversion_rate_bp: number;
}
export interface GrowthTrial {
  id: string;
  name: string;
  slug: string;
  owner_email: string | null;
  trial_ends_at: string | null;
  days_left: number | null;
  has_company: boolean;
  has_posted: boolean;
  members: number;
  activation_score: number;
}

const data = <T>(r: { data: T }): T => r.data;

export const getFunnel = async (from?: string, to?: string): Promise<Funnel> => {
  const q = new URLSearchParams();
  if (from) q.set('from', from);
  if (to) q.set('to', to);
  const s = q.toString();
  return data(await adminHttp.send<{ data: Funnel }>('GET', `/growth/funnel${s ? `?${s}` : ''}`));
};

export const getTrials = () => adminHttp.send<{ data: GrowthTrial[] }>('GET', '/growth/trials').then(data);

export const setNudges = (enabled: boolean, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: { enabled: boolean } }>('PUT', '/growth/nudges', { enabled }, intent).then(data);

export interface QuoteBody {
  plan_code: string;
  interval: 'month' | 'year';
  currency: string;
  custom_amount: number;
  collection: 'offline' | 'stripe';
  note?: string;
}

export const convertWithQuote = (tenantId: string, body: QuoteBody, intent: Intent = createIntent()) =>
  adminHttp.send<{ data: { id: string; status: string; custom_amount: number } }>('POST', `/tenants/${encodeURIComponent(tenantId)}/convert-quote`, body, intent).then(data);
