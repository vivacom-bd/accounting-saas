/** The organisation's own billing (plan Task 24, 4b.8): plan, usage meters, live subscription and recent invoices. */
import { http } from '../http';

export interface BillingMeter {
  key: string;
  limit: number | null;
  used: number;
  ratio: number | null;
  level: 'ok' | 'warn' | 'over';
}
export interface OrgSubscription {
  status: string;
  interval: string;
  currency: string;
  custom_amount: number | null;
  quantity: number;
  trial_ends_at: string | null;
  current_period_end: string | null;
  cancel_at: string | null;
  pause_until: string | null;
  plan_code: string;
  plan_name: string;
  monthly_amount: number;
}
export interface OrgInvoice {
  id: string;
  number: string | null;
  status: string;
  currency: string;
  subtotal: number;
  tax: number;
  total: number;
  amount_paid: number;
  amount_refunded: number;
  due_at: string | null;
  paid_at: string | null;
  pdf_url: string | null;
  created_at: string;
}
export interface BillingSummary {
  plan_code: string | null;
  meters: BillingMeter[];
  subscription: OrgSubscription | null;
  invoices: OrgInvoice[];
}

export async function fetchBillingSummary(): Promise<BillingSummary> {
  const res = await http.send<{ data: BillingSummary }>('GET', '/v1/org/billing');
  return res.data;
}
