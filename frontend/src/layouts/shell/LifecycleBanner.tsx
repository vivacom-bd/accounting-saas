/**
 * The lifecycle banner for the current organisation (plan Task 24): trial, past_due, read_only, suspended, cancelled.
 * It reads the lifecycle from the session; a trial's days left come from the billing summary when the person may read it.
 */
import React from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { IS_LIVE } from '@/api/config';
import { useMe } from '@/data/session';
import { currentTenant } from '@/api/adapters/session';
import { fetchBillingSummary } from '@/api/live/orgBilling';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

const TONE: Record<string, string> = {
  trial: 'bg-accent/10 border-accent/30 text-text-primary',
  past_due: 'bg-warning-soft border-warning/30 text-text-primary',
  read_only: 'bg-warning-soft border-warning/30 text-text-primary',
  suspended: 'bg-danger-soft border-danger/30 text-text-primary',
  cancelled: 'bg-danger-soft border-danger/30 text-text-primary',
};

export const LifecycleBanner: React.FC = () => {
  const me = useMe();
  const tenant = me.data ? currentTenant(me.data) : undefined;
  const lifecycle = tenant?.lifecycle;

  const billing = useQuery({
    queryKey: ['org', 'billing'], queryFn: fetchBillingSummary, enabled: IS_LIVE && lifecycle === 'trial', retry: false, staleTime: 60_000,
  });

  if (!IS_LIVE || !lifecycle || lifecycle === 'active') return null;

  let text: React.ReactNode = null;
  if (lifecycle === 'trial') {
    const ends = billing.data?.subscription?.trial_ends_at;
    text = <>You are on a trial{ends ? ` — it ends on ${formatDate(ends.slice(0, 10))}` : ''}. Choose a plan to keep working without interruption.</>;
  } else if (lifecycle === 'past_due') {
    text = <>Your last payment did not go through. Update your billing details to avoid interruption.</>;
  } else if (lifecycle === 'read_only') {
    text = <>Your organisation is read-only: you can view everything, but changes are disabled until payment is made.</>;
  } else if (lifecycle === 'suspended') {
    text = <>Your organisation is suspended. Contact us to restore access.</>;
  } else if (lifecycle === 'cancelled') {
    text = <>Your subscription is cancelled. Your data is retained during the deletion window; contact us to reactivate.</>;
  }
  if (!text) return null;

  return (
    <div className={cn('border-b px-3 py-2 text-[12px] flex items-center gap-2', TONE[lifecycle] ?? TONE.trial)} role="status">
      <span className="flex-1">{text}</span>
      <Link to="/org/billing" className="text-accent hover:underline shrink-0">Billing</Link>
    </div>
  );
};
