/**
 * Saved layouts of one report (3c.23): a calm menu in the report shell. Pick one to apply it, save the current choices under
 * a name (private, or shared with the company), mark a default, and set up emailed delivery from here. The look follows the
 * saved views of the lists (components/domain/DataTableViews).
 */
import React, { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Bookmark, CalendarClock, Check, Star, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { IntentSlot } from '@/api/intentSlot';
import type { ReportSelection } from '@/api/adapters/report';
import { paramsFromSelection, RULE_LABELS, ruleChoices, ruleOf, selectionFromResolved, type PeriodRule } from '@/api/adapters/reportLayouts';
import type { ReportKind } from '@/api/adapters/reportSelection';
import type { ReportLayout } from '@/api/live/reportLayouts';
import type { ReportName } from '@/api/live/reports';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { useDeleteLayout, useLayouts, useSaveLayout } from '@/data/reportLayouts';
import { REPORT_PARAMS } from '@/data/reports';
import { inputClass } from '@/pages/Sales/live/parts';
import { reportErrorText, ScheduleDialog } from './ScheduleDialog';

const URL_KEYS = ['period', 'from', 'to', 'asof', 'basis', 'cmp', 'zero', 'account', 'contact', 'budget', 'view', 'by'];

export const LayoutsMenu: React.FC<{ report: ReportName; kind: ReportKind; selection: ReportSelection; onApply: (next: ReportSelection) => void }> = ({ report, kind, selection, onApply }) => {
  const layouts = useLayouts(report);
  const save = useSaveLayout(report);
  const remove = useDeleteLayout(report);
  const [sp] = useSearchParams();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [visibility, setVisibility] = useState<'private' | 'company'>('private');
  const [rule, setRule] = useState<PeriodRule>('fixed');
  const [asDefault, setAsDefault] = useState(false);
  const [error, setError] = useState('');
  const [scheduling, setScheduling] = useState<ReportLayout | null>(null);
  const [active, setActive] = useState<string | null>(null);
  const slot = useRef(new IntentSlot()).current;
  const defaultTried = useRef(false);

  const list = layouts.data ?? [];
  const apply = (l: ReportLayout) => {
    if (!l.resolved) return;
    onApply(selectionFromResolved(l.resolved, kind, selection));
    setActive(l.id);
    setOpen(false);
  };

  // Opening a report with no choices in its address uses the person's default layout, once.
  const hasChoices = URL_KEYS.some((k) => sp.has(k));
  useEffect(() => {
    if (defaultTried.current || layouts.isLoading || !layouts.data) return;
    defaultTried.current = true;
    const d = layouts.data.find((l) => l.is_default && l.mine && l.resolved);
    if (d && !hasChoices && d.resolved) {
      onApply(selectionFromResolved(d.resolved, kind, selection));
      setActive(d.id);
    }
  }, [layouts.isLoading, layouts.data, hasChoices, kind, onApply, selection]);

  const submit = async () => {
    const trimmed = name.trim();
    if (!trimmed) return;
    setError('');
    const body = { report, name: trimmed, visibility, is_default: asDefault, params: paramsFromSelection(report, selection, REPORT_PARAMS[report], rule) } as Parameters<typeof save.mutateAsync>[0]['body'];
    try {
      const saved = await save.mutateAsync({ body, intent: slot.for(JSON.stringify(body)) });
      slot.clear();
      setActive(saved.id);
      setName('');
      setAsDefault(false);
      toast.success(`Saved layout “${trimmed}”`);
    } catch (e) {
      setError(reportErrorText(e));
    }
  };

  const setDefault = async (l: ReportLayout) => {
    const body = { report, name: l.name, visibility: l.visibility as 'private' | 'company', is_default: !l.is_default, params: l.params, version: l.version } as Parameters<typeof save.mutateAsync>[0]['body'];
    try {
      await save.mutateAsync({ body, intent: slot.for(JSON.stringify([l.id, body])), id: l.id });
      slot.clear();
    } catch (e) {
      toast.error(reportErrorText(e));
    }
  };

  const drop = async (l: ReportLayout) => {
    try {
      await remove.mutateAsync({ id: l.id, intent: slot.for(`delete:${l.id}`) });
      slot.clear();
      if (active === l.id) setActive(null);
      toast.success(`Removed “${l.name}”`);
    } catch (e) {
      toast.error(reportErrorText(e));
    }
  };

  return (
    <>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <button
            type="button"
            aria-label="Saved layouts"
            className="flex h-8 cursor-pointer items-center gap-1.5 rounded-md border border-border bg-surface px-3 text-[13px] font-medium text-text-primary transition-colors hover:bg-surface-neutral print:hidden"
          >
            <Bookmark className="size-3.5" />
            <span>Layouts</span>
            {list.length > 0 && <span className="rounded-[3px] bg-surface-neutral px-1.5 text-[10px] tabular-nums">{list.length}</span>}
          </button>
        </PopoverTrigger>
        <PopoverContent align="end" className="w-80 p-2" data-testid="layouts-menu">
          <div className="px-2 pb-1 pt-0.5 text-[11px] font-semibold text-text-muted">Saved layouts</div>
          {layouts.isError ? (
            <p className="px-2 pb-2 text-xs text-danger">Layouts could not be loaded.</p>
          ) : list.length === 0 ? (
            <p className="px-2 pb-2 text-xs text-text-muted">Set the report up the way you want it, then name it below. A layout can follow the calendar, such as “Last month”.</p>
          ) : (
            <ul className="mb-2 flex max-h-60 flex-col gap-0.5 overflow-auto">
              {list.map((l) => (
                <li key={l.id} className="group flex items-center gap-1 rounded-md hover:bg-surface-neutral">
                  <button
                    type="button"
                    disabled={!l.resolved}
                    title={l.problem ?? undefined}
                    onClick={() => apply(l)}
                    className="flex min-h-8 min-w-0 flex-1 cursor-pointer items-center gap-2 px-2 py-1 text-left text-[13px] text-text-primary disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    {active === l.id ? <Check className="size-3.5 shrink-0 text-accent" /> : <span className="size-3.5 shrink-0" />}
                    <span className="min-w-0">
                      <span className="block truncate">{l.name}</span>
                      <span className="block truncate text-[11px] text-text-muted">
                        {RULE_LABELS[ruleOf(l.params)] ?? 'Fixed period'}
                        {l.visibility === 'company' ? (l.mine ? ' · shared' : ` · shared by ${l.owner_name}`) : ''}
                        {l.schedule ? ` · ${l.schedule.active ? l.schedule.description : 'schedule paused'}` : ''}
                      </span>
                    </span>
                    {l.is_default && <span className="rounded bg-accent-soft px-1 text-[10px] font-medium text-accent">Default</span>}
                  </button>
                  {l.mine && (
                    <>
                      <button type="button" title="Open this layout by default" aria-label={l.is_default ? `Remove ${l.name} as default` : `Make ${l.name} the default`} onClick={() => void setDefault(l)} className={cn('flex size-7 cursor-pointer items-center justify-center rounded-md text-text-muted hover:text-warning', l.is_default && 'text-warning')}>
                        <Star className={cn('size-3.5', l.is_default && 'fill-current')} />
                      </button>
                      <button type="button" title="Email on a schedule" aria-label={`Schedule ${l.name}`} onClick={() => { setScheduling(l); setOpen(false); }} className={cn('flex size-7 cursor-pointer items-center justify-center rounded-md text-text-muted hover:text-accent', l.schedule?.active && 'text-accent')}>
                        <CalendarClock className="size-3.5" />
                      </button>
                      <button type="button" title="Delete layout" aria-label={`Delete ${l.name}`} onClick={() => void drop(l)} className="flex size-7 cursor-pointer items-center justify-center rounded-md text-text-muted hover:text-danger">
                        <Trash2 className="size-3.5" />
                      </button>
                    </>
                  )}
                </li>
              ))}
            </ul>
          )}
          <div className="flex flex-col gap-1.5 border-t border-border px-1 pt-2">
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  void submit();
                }
              }}
              placeholder="Name this layout"
              aria-label="Layout name"
              maxLength={80}
              className={cn(inputClass, 'w-full')}
            />
            <div className="flex gap-1.5">
              <select aria-label="Period" value={rule} onChange={(e) => setRule(e.target.value as PeriodRule)} className={cn(inputClass, 'min-w-0 flex-1')}>
                {ruleChoices(kind).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
              </select>
              <select aria-label="Who can use it" value={visibility} onChange={(e) => setVisibility(e.target.value as 'private' | 'company')} className={cn(inputClass, 'w-28')}>
                <option value="private">Only me</option>
                <option value="company">Company</option>
              </select>
            </div>
            <div className="flex items-center justify-between gap-2">
              <label className="flex cursor-pointer items-center gap-1.5 text-xs text-text-secondary">
                <input type="checkbox" checked={asDefault} onChange={(e) => setAsDefault(e.target.checked)} />
                Open by default
              </label>
              <button type="button" onClick={() => void submit()} disabled={!name.trim() || save.isPending} className="h-8 cursor-pointer rounded-md bg-accent px-3 text-[13px] font-medium text-white transition hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50">
                Save
              </button>
            </div>
            {error && <p role="alert" className="text-xs text-danger">{error}</p>}
          </div>
        </PopoverContent>
      </Popover>
      {scheduling && <ScheduleDialog layout={scheduling} onClose={() => setScheduling(null)} />}
    </>
  );
};
