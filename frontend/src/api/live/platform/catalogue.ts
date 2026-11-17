/**
 * Live plans, add-ons and coupons calls (plan Task 22). The generated admin schema types these responses as `unknown`,
 * so each call names its response shape (api/adapters/platform/catalogue.ts) and the cast sits here, once per call.
 */
import { adminHttp } from '../../adminHttp';
import { createIntent, type Intent } from '../../idempotency';
import type { AddonBody, ApiAddon, ApiCoupon, ApiPlan, ApiPlanVersion, CouponBody, VersionBody } from '../../adapters/platform/catalogue';

type Data<T> = { data: T };
const call = <T>(method: string, path: string, body?: unknown, intent?: Intent) => adminHttp.send<Data<T>>(method, path, body, intent).then((r) => r.data);

export const listPlans = () => call<ApiPlan[]>('GET', '/plans');
export const createPlan = (body: VersionBody & { code: string; name: string; description?: string }, intent: Intent = createIntent()) => call<ApiPlan>('POST', '/plans', body, intent);
export const publishPlanVersion = (planId: string, body: VersionBody, intent: Intent = createIntent()) =>
  call<ApiPlanVersion>('POST', `/plans/${encodeURIComponent(planId)}/versions`, body, intent);
export const archivePlan = (planId: string, intent: Intent = createIntent()) => call<ApiPlan>('POST', `/plans/${encodeURIComponent(planId)}/archive`, undefined, intent);

export const listAddons = () => call<ApiAddon[]>('GET', '/addons');
export const upsertAddon = (code: string, body: AddonBody, intent: Intent = createIntent()) => call<ApiAddon>('PUT', `/addons/${encodeURIComponent(code)}`, body, intent);

export const listCoupons = () => call<ApiCoupon[]>('GET', '/coupons');
export const createCoupon = (body: CouponBody, intent: Intent = createIntent()) => call<ApiCoupon>('POST', '/coupons', body, intent);
export const archiveCoupon = (id: string, intent: Intent = createIntent()) => call<ApiCoupon>('POST', `/coupons/${encodeURIComponent(id)}/archive`, undefined, intent);
