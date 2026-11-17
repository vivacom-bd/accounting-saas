/** Small shared pieces of the live tenants screens: form field, input and button classes, status pill, dates. */
import React from 'react';
import { cn } from '@/lib/utils';
import { formatDate } from '@/lib/format';
import { LIFECYCLE_NAMES, type LifecycleStatus } from '@/lib/tenantLifecycle';

export const inputCls =
  'h-8 px-2.5 w-full text-xs bg-surface border border-border rounded-md text-text-primary placeholder:text-text-muted hover:border-border-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/50 disabled:opacity-50';
export const btnPrimary =
  'h-8 px-3 rounded-md bg-accent text-white hover:bg-accent-hover text-[13px] font-medium transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed';
export const btnGhost =
  'h-8 px-3 rounded-md border border-border bg-surface text-text-primary hover:bg-surface-neutral text-[13px] font-medium transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed';
export const btnDanger =
  'h-8 px-3 rounded-md border border-danger/40 bg-danger-soft text-danger hover:bg-danger-soft/70 text-[13px] font-medium transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed';

export const Field: React.FC<{ label: string; htmlFor?: string; error?: string; hint?: string; children: React.ReactNode }> = ({ label, htmlFor, error, hint, children }) => (
  <div className="flex flex-col gap-1">
    <label htmlFor={htmlFor} className="text-[12px] font-medium text-text-secondary">
      {label}
    </label>
    {children}
    {hint && !error && <span className="text-[11px] text-text-muted">{hint}</span>}
    {error && (
      <span role="alert" className="text-[11px] text-danger">
        {error}
      </span>
    )}
  </div>
);

const STATUS_TONE: Record<string, string> = {
  trial: 'bg-info-soft text-info',
  active: 'bg-success-soft text-success',
  past_due: 'bg-warning-soft text-warning',
  read_only: 'bg-warning-soft text-warning',
  suspended: 'bg-danger-soft text-danger',
  cancelled: 'bg-surface-neutral text-text-secondary',
  deleted: 'bg-surface-neutral text-text-muted',
};

export const LifecyclePill: React.FC<{ status: string }> = ({ status }) => (
  <span className={cn('px-1.5 py-px rounded-[3px] text-[11px] font-medium', STATUS_TONE[status] ?? 'bg-surface-neutral text-text-secondary')}>
    {LIFECYCLE_NAMES[status as LifecycleStatus] ?? status}
  </span>
);

export const dateOrDash = (v: string | undefined | null): string => (v ? formatDate(v.slice(0, 10)) : '—');

export const ErrorLine: React.FC<{ message: string | null }> = ({ message }) =>
  message ? (
    <p role="alert" className="text-[12px] text-danger">
      {message}
    </p>
  ) : null;
