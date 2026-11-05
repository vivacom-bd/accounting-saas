/** Live calls for saved report layouts and scheduled delivery (3c.23). */
import type { components } from '../schema';
import { ApiError } from '../errors';
import { http } from '../http';
import type { Intent } from '../idempotency';

export type ReportLayout = components['schemas']['ReportLayoutResource'];
export type ReportSchedule = components['schemas']['ReportScheduleResource'];
export type ReportLayoutBody = components['schemas']['ReportLayoutRequest'];
export type ReportScheduleBody = components['schemas']['ReportScheduleRequest'];
export interface Person {
  id: string;
  name: string;
  email: string;
}

const root = (companyId: string) => `/v1/companies/${encodeURIComponent(companyId)}/reports`;

export const fetchLayouts = async (companyId: string, report: string): Promise<ReportLayout[]> =>
  (await http.send<{ data: ReportLayout[] }>('GET', `${root(companyId)}/layouts?report=${encodeURIComponent(report)}`)).data;

export const saveLayout = async (companyId: string, body: ReportLayoutBody, intent: Intent, id?: string): Promise<ReportLayout> =>
  (await http.send<{ data: ReportLayout }>(id ? 'PUT' : 'POST', `${root(companyId)}/layouts${id ? `/${id}` : ''}`, body, intent)).data;

export const deleteLayout = async (companyId: string, id: string, intent: Intent): Promise<void> => {
  await http.send('DELETE', `${root(companyId)}/layouts/${id}`, undefined, intent);
};

/** The schedule on a layout of yours, or null when it has none. */
export async function fetchSchedule(companyId: string, layoutId: string): Promise<ReportSchedule | null> {
  try {
    return (await http.send<{ data: ReportSchedule }>('GET', `${root(companyId)}/layouts/${layoutId}/schedule`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) return null;
    throw e;
  }
}

export const saveSchedule = async (companyId: string, layoutId: string, body: ReportScheduleBody, intent: Intent): Promise<ReportSchedule> =>
  (await http.send<{ data: ReportSchedule }>('PUT', `${root(companyId)}/layouts/${layoutId}/schedule`, body, intent)).data;

export const deleteSchedule = async (companyId: string, layoutId: string, intent: Intent): Promise<void> => {
  await http.send('DELETE', `${root(companyId)}/layouts/${layoutId}/schedule`, undefined, intent);
};

export const fetchRecipients = async (companyId: string): Promise<Person[]> => (await http.send<{ data: Person[] }>('GET', `${root(companyId)}/schedule-recipients`)).data;
