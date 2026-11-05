/** Scheduled email delivery of one saved layout (3c.23): friendly choices, recipients, format and the run history. */
import React, { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ApiError } from '@/api/errors';
import { describeError } from '@/api/messages';
import { IntentSlot } from '@/api/intentSlot';
import type { ReportLayout, ReportSchedule } from '@/api/live/reportLayouts';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Switch } from '@/components/ui/switch';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useDeleteSchedule, useSaveSchedule, useSchedule, useScheduleRecipients } from '@/data/reportLayouts';
import { BTN_ACCENT, BTN_GHOST, inputClass } from '@/pages/Sales/live/parts';

const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const FORMATS = [
  { value: 'pdf', label: 'PDF' },
  { value: 'xlsx', label: 'Excel' },
  { value: 'csv', label: 'CSV' },
] as const;

export function reportErrorText(e: unknown): string {
  if (e instanceof ApiError && e.violations?.length) return e.violations[0].message;
  if (e instanceof ApiError && e.isValidation && e.errors) return Object.values(e.errors)[0]?.[0] ?? describeError(e);
  return describeError(e);
}

const STATUS: Record<string, { label: string; tone: string }> = {
  sent: { label: 'Sent', tone: 'text-success' },
  failed: { label: 'Not sent', tone: 'text-danger' },
  skipped: { label: 'Stopped', tone: 'text-warning' },
  queued: { label: 'Waiting', tone: 'text-text-muted' },
  running: { label: 'Sending', tone: 'text-text-muted' },
};

const Row: React.FC<{ id: string; label: string; children: React.ReactNode }> = ({ id, label, children }) => (
  <div className="grid grid-cols-[7rem_1fr] items-start gap-3">
    <label htmlFor={id} className="pt-2 text-xs font-medium text-text-secondary">{label}</label>
    <div className="flex flex-wrap items-center gap-2">{children}</div>
  </div>
);

export const ScheduleDialog: React.FC<{ layout: ReportLayout; onClose: () => void }> = ({ layout, onClose }) => {
  const schedule = useSchedule(layout.id);
  const recipients = useScheduleRecipients(true);
  const save = useSaveSchedule(layout.report, layout.id);
  const remove = useDeleteSchedule(layout.report, layout.id);
  const slot = useRef(new IntentSlot()).current;
  const existing: ReportSchedule | null | undefined = schedule.data;

  const [frequency, setFrequency] = useState('weekly');
  const [weekday, setWeekday] = useState(1);
  const [monthDay, setMonthDay] = useState('1');
  const [hour, setHour] = useState(7);
  const [format, setFormat] = useState<'pdf' | 'xlsx' | 'csv'>('pdf');
  const [people, setPeople] = useState<string[]>([]);
  const [emails, setEmails] = useState('');
  const [active, setActive] = useState(true);
  const [error, setError] = useState('');
  const loaded = useRef(false);

  useEffect(() => {
    if (loaded.current || schedule.isLoading) return;
    loaded.current = true;
    if (!existing) return;
    setFrequency(existing.frequency);
    setWeekday(existing.weekday ?? 1);
    setMonthDay(existing.last_day ? 'last' : String(existing.month_day ?? 1));
    setHour(existing.hour);
    setFormat(existing.format as 'pdf' | 'xlsx' | 'csv');
    setPeople(existing.recipient_user_ids);
    setEmails(existing.recipient_emails.join(', '));
    setActive(existing.active);
  }, [existing, schedule.isLoading]);

  const monthly = frequency === 'monthly' || frequency === 'quarterly';
  const forbidden = recipients.error instanceof ApiError && recipients.error.status === 403;

  const submit = async () => {
    setError('');
    const body = {
      frequency,
      weekday: frequency === 'weekly' ? weekday : null,
      month_day: monthly && monthDay !== 'last' ? Number(monthDay) : null,
      last_day: monthly && monthDay === 'last',
      hour,
      format,
      recipient_user_ids: people,
      recipient_emails: emails.split(/[\s,;]+/).filter(Boolean),
      active,
      ...(existing ? { version: existing.version } : {}),
    } as Parameters<typeof save.mutateAsync>[0]['body'];
    try {
      await save.mutateAsync({ body, intent: slot.for(JSON.stringify(body)) });
      slot.clear();
      toast.success(active ? 'Schedule saved' : 'Schedule saved and paused');
      onClose();
    } catch (e) {
      setError(reportErrorText(e));
    }
  };

  const stop = async () => {
    try {
      await remove.mutateAsync({ intent: slot.for('delete') });
      slot.clear();
      toast.success('Schedule removed');
      onClose();
    } catch (e) {
      setError(reportErrorText(e));
    }
  };

  const busy = save.isPending || remove.isPending;
  const paused = existing && !existing.active;

  return (
    <Dialog open onOpenChange={(o) => !o && !busy && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-xl overflow-auto rounded-lg border border-border bg-surface-elevated p-6 shadow-popover" data-testid="schedule-dialog">
        <DialogHeader>
          <DialogTitle className="text-base font-semibold text-text-primary">Email “{layout.name}” on a schedule</DialogTitle>
          <DialogDescription className="text-xs text-text-muted">
            The report goes out as an attachment and runs as you, so it only contains what you can see. The email itself shows no figures.
          </DialogDescription>
        </DialogHeader>

        {paused && (
          <p role="status" className="rounded-md border border-warning/40 bg-warning-soft px-3 py-2 text-xs text-text-primary" data-testid="schedule-paused">
            {existing?.paused_reason === 'owner_lost_rights'
              ? 'This schedule was stopped because you no longer have the rights it needs. Once that is fixed, turn it back on and save.'
              : 'This schedule is paused. Nothing is sent until you turn it back on.'}
          </p>
        )}
        {layout.params && !('rule' in layout.params) && (
          <p role="status" className="rounded-md border border-border bg-surface-neutral px-3 py-2 text-xs text-text-secondary">
            This layout keeps one fixed period, so every email would show the same figures. Save it again with a period such as “Last month” first.
          </p>
        )}

        <div className="flex flex-col gap-3">
          <Row id="sc-freq" label="How often">
            <select id="sc-freq" value={frequency} onChange={(e) => setFrequency(e.target.value)} className={cn(inputClass, 'w-40')}>
              <option value="daily">Every day</option>
              <option value="weekly">Every week</option>
              <option value="monthly">Every month</option>
              <option value="quarterly">Every quarter</option>
            </select>
            {frequency === 'weekly' && (
              <select aria-label="Day of the week" value={weekday} onChange={(e) => setWeekday(Number(e.target.value))} className={cn(inputClass, 'w-36')}>
                {WEEKDAYS.map((d, i) => <option key={d} value={i + 1}>{d}</option>)}
              </select>
            )}
            {monthly && (
              <select aria-label="Day of the month" value={monthDay} onChange={(e) => setMonthDay(e.target.value)} className={cn(inputClass, 'w-40')}>
                {Array.from({ length: 28 }, (_, i) => <option key={i + 1} value={String(i + 1)}>{`Day ${i + 1}`}</option>)}
                <option value="last">Last day</option>
              </select>
            )}
            {frequency === 'quarterly' && <span className="text-[11px] text-text-muted">January, April, July and October</span>}
          </Row>
          <Row id="sc-hour" label="At">
            <select id="sc-hour" value={hour} onChange={(e) => setHour(Number(e.target.value))} className={cn(inputClass, 'w-28')}>
              {Array.from({ length: 24 }, (_, h) => <option key={h} value={h}>{`${String(h).padStart(2, '0')}:00`}</option>)}
            </select>
            <span className="text-[11px] text-text-muted">in the company’s time zone</span>
          </Row>
          <Row id="sc-format" label="Format">
            <div role="radiogroup" aria-label="Format" className="flex gap-3 text-[13px]">
              {FORMATS.map((f) => (
                <label key={f.value} className="flex cursor-pointer items-center gap-1.5">
                  <input type="radio" name="sc-format" checked={format === f.value} onChange={() => setFormat(f.value)} />
                  {f.label}
                </label>
              ))}
            </div>
          </Row>
          <Row id="sc-people" label="Send to">
            {forbidden ? (
              <p className="text-xs text-danger">Your role cannot schedule reports. Ask an owner or finance manager.</p>
            ) : (
              <div className="flex w-full flex-col gap-1" id="sc-people">
                {(recipients.data ?? []).map((p) => (
                  <label key={p.id} className="flex cursor-pointer items-center gap-2 text-[13px]">
                    <input type="checkbox" checked={people.includes(p.id)} onChange={(e) => setPeople(e.target.checked ? [...people, p.id] : people.filter((x) => x !== p.id))} />
                    <span>{p.name}</span>
                    <span className="text-[11px] text-text-muted">{p.email}</span>
                  </label>
                ))}
              </div>
            )}
          </Row>
          <Row id="sc-emails" label="Other addresses">
            <input id="sc-emails" value={emails} onChange={(e) => setEmails(e.target.value)} placeholder="auditor@example.com, owner@example.com" className={cn(inputClass, 'w-full')} />
          </Row>
          {existing && (
            <Row id="sc-active" label="Active">
              <Switch id="sc-active" checked={active} onCheckedChange={setActive} aria-label="Active" />
              <span className="text-[11px] text-text-muted">{active ? (existing.next_run_at ? `Next: ${formatDate(existing.next_run_at.slice(0, 10))} at ${existing.next_run_at.slice(11)}` : 'Runs on schedule') : 'Paused'}</span>
            </Row>
          )}
        </div>

        <p className="text-[11px] leading-snug text-text-muted">
          If a report cannot be made or sent (it is too large, a rule changed, you lost a right) nothing is sent for that date, the run is recorded below and you get a notification. It is not retried; the next due date goes ahead as normal.
        </p>

        {existing && existing.runs.length > 0 && (
          <div>
            <h3 className="mb-1 text-xs font-semibold text-text-primary">Recent runs</h3>
            <ul className="flex max-h-40 flex-col divide-y divide-border overflow-auto rounded-md border border-border text-xs" data-testid="schedule-runs">
              {existing.runs.map((r) => {
                const s = STATUS[r.status] ?? STATUS.queued;
                return (
                  <li key={r.id} className="flex flex-col gap-0.5 px-3 py-1.5">
                    <div className="flex items-center justify-between">
                      <span className="tabular-nums text-text-primary">{formatDate(r.due_on)}</span>
                      <span className={cn('font-medium', s.tone)}>{s.label}{r.status === 'sent' ? ` to ${r.recipients}` : ''}</span>
                    </div>
                    {r.message && <span className="text-text-muted">{r.message}</span>}
                  </li>
                );
              })}
            </ul>
          </div>
        )}

        {error && <p role="alert" className="text-xs font-medium text-danger">{error}</p>}
        <DialogFooter className="items-center justify-between sm:justify-between">
          {existing ? (
            <button type="button" onClick={() => void stop()} disabled={busy} className="text-[13px] font-medium text-danger hover:underline disabled:opacity-50">Stop and remove</button>
          ) : <span />}
          <div className="flex gap-2">
            <button type="button" onClick={onClose} disabled={busy} className={BTN_GHOST}>Cancel</button>
            <button type="button" onClick={() => void submit()} disabled={busy || forbidden} className={BTN_ACCENT}>{busy ? 'Saving…' : 'Save schedule'}</button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
