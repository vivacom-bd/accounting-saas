/** Saved report layouts and scheduled delivery, live side (3c.23). Query keys: ['report-layouts', companyId, report] and ['report-schedule', companyId, layoutId]. */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as live from '@/api/live/reportLayouts';
import type { Intent } from '@/api/idempotency';
import { useRouteCompany } from './session';

const layoutsKey = (companyId: string, report: string) => ['report-layouts', companyId, report] as const;
const scheduleKey = (companyId: string, layoutId: string) => ['report-schedule', companyId, layoutId] as const;

export function useLayouts(report: string) {
  const company = useRouteCompany();
  return useQuery({ queryKey: layoutsKey(company.id, report), queryFn: () => live.fetchLayouts(company.id, report), retry: false, refetchOnWindowFocus: false });
}

export function useSaveLayout(report: string) {
  const company = useRouteCompany();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ body, intent, id }: { body: live.ReportLayoutBody; intent: Intent; id?: string }) => live.saveLayout(company.id, body, intent, id),
    onSuccess: () => qc.invalidateQueries({ queryKey: layoutsKey(company.id, report) }),
  });
}

export function useDeleteLayout(report: string) {
  const company = useRouteCompany();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, intent }: { id: string; intent: Intent }) => live.deleteLayout(company.id, id, intent),
    onSuccess: () => qc.invalidateQueries({ queryKey: layoutsKey(company.id, report) }),
  });
}

export function useSchedule(layoutId: string) {
  const company = useRouteCompany();
  return useQuery({ queryKey: scheduleKey(company.id, layoutId), queryFn: () => live.fetchSchedule(company.id, layoutId), retry: false, refetchOnWindowFocus: false });
}

export function useScheduleRecipients(enabled: boolean) {
  const company = useRouteCompany();
  return useQuery({ queryKey: ['report-recipients', company.id], queryFn: () => live.fetchRecipients(company.id), enabled, retry: false, refetchOnWindowFocus: false });
}

export function useSaveSchedule(report: string, layoutId: string) {
  const company = useRouteCompany();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ body, intent }: { body: live.ReportScheduleBody; intent: Intent }) => live.saveSchedule(company.id, layoutId, body, intent),
    onSuccess: () => Promise.all([qc.invalidateQueries({ queryKey: scheduleKey(company.id, layoutId) }), qc.invalidateQueries({ queryKey: layoutsKey(company.id, report) })]),
  });
}

export function useDeleteSchedule(report: string, layoutId: string) {
  const company = useRouteCompany();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ intent }: { intent: Intent }) => live.deleteSchedule(company.id, layoutId, intent),
    onSuccess: () => Promise.all([qc.invalidateQueries({ queryKey: scheduleKey(company.id, layoutId) }), qc.invalidateQueries({ queryKey: layoutsKey(company.id, report) })]),
  });
}
