/**
 * The platform admin API client (10_SaaS_Admin, plan Task 20): the same wrapper as `http.ts`, typed over the
 * admin OpenAPI document and pointed at /api/admin. The admin SPA keeps its own session and CSRF cookies
 * (`ledgerly_admin_session`, `XSRF-TOKEN-ADMIN`), so the token it echoes is never the tenant one.
 */
import type { paths } from './schema-admin';
import { API_BASE_URL } from './config';
import { HttpClient, readBrowserCookie, type HttpClientConfig } from './http';
import type { ApiError } from './errors';

export type AdminPaths = paths;

export const ADMIN_API_PREFIX = '/api/admin';
export const ADMIN_CSRF_COOKIE = 'XSRF-TOKEN-ADMIN';

export type AdminHttpClient = HttpClient<paths>;

export function createAdminHttp(
  cfg: Pick<HttpClientConfig, 'baseUrl' | 'fetch'> & Partial<Pick<HttpClientConfig, 'readCookie' | 'onUnauthenticated'>>,
): AdminHttpClient {
  return new HttpClient<paths>({
    readCookie: readBrowserCookie,
    ...cfg,
    apiPrefix: ADMIN_API_PREFIX,
    csrfPath: `${ADMIN_API_PREFIX}/csrf-cookie`,
    csrfCookie: ADMIN_CSRF_COOKIE,
  });
}

let unauthenticatedListener: ((e: ApiError) => void) | undefined;
/** The admin session module registers here; the client stays free of React and router imports. */
export function setAdminUnauthenticatedListener(fn: ((e: ApiError) => void) | undefined): void {
  unauthenticatedListener = fn;
}

export const adminHttp: AdminHttpClient = createAdminHttp({
  baseUrl: API_BASE_URL,
  fetch: (...a) => globalThis.fetch(...a),
  onUnauthenticated: (e) => unauthenticatedListener?.(e),
});
