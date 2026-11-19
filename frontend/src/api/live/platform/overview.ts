/** Live platform overview (plan Task 19): MRR/ARR, movements, tenants, trials, cohorts — one figure per currency. */
import { adminHttp } from '../../adminHttp';

export interface MoneyByCurrency {
  currency: string;
  amount: number;
}
export interface Movement {
  month: string;
  currency: string;
  new: number;
  expansion: number;
  contraction: number;
  churn: number;
  reactivation: number;
}
export interface Overview {
  mrr: MoneyByCurrency[];
  arr: MoneyByCurrency[];
  movements: Movement[];
  active_tenants: number;
  trials: number;
  trial_conversion_bp: number;
  failed_payments: { count: number; by_currency: MoneyByCurrency[] };
  signups_today: number;
  revenue_by_plan: Array<{ plan_code: string; currency: string; mrr: number }>;
  geography: Array<{ country: string | null; tenants: number }>;
  cohorts: Array<{ cohort_month: string; size: number; retained: number[] }>;
  open_items: { approvals: number; gdpr: number };
}

export const getOverview = (months = 12): Promise<Overview> =>
  adminHttp.send<{ data: Overview }>('GET', `/overview?months=${months}`).then((r) => r.data);
