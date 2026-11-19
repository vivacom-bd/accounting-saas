/** Platform announcements for this organisation (plan Task 15), shown as dismissible banners. Live mode only. */
import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchAnnouncements } from '@/api/live/announcements';
import { IS_LIVE } from '@/api/config';
import { cn } from '@/lib/utils';

const TONE: Record<string, string> = {
  info: 'bg-accent/10 border-accent/30 text-text-primary',
  warning: 'bg-warning-soft border-warning/30 text-text-primary',
  maintenance: 'bg-danger-soft border-danger/30 text-text-primary',
};

export const AnnouncementBanner: React.FC = () => {
  const q = useQuery({ queryKey: ['announcements'], queryFn: fetchAnnouncements, enabled: IS_LIVE, staleTime: 60_000, retry: false });
  const [dismissed, setDismissed] = useState<Set<string>>(new Set());
  if (!IS_LIVE || !q.data) return null;
  const shown = q.data.filter((a) => !dismissed.has(a.id));
  if (shown.length === 0) return null;

  return (
    <div className="flex flex-col">
      {shown.map((a) => (
        <div key={a.id} className={cn('border-b px-3 py-2 text-[12px] flex items-start gap-2', TONE[a.kind] ?? TONE.info)} role="status">
          <div className="flex-1">
            <strong>{a.title}</strong> <span className="text-text-secondary">{a.body}</span>
          </div>
          <button type="button" onClick={() => setDismissed((s) => new Set(s).add(a.id))} className="text-text-muted hover:text-text-primary cursor-pointer" aria-label="Dismiss">×</button>
        </div>
      ))}
    </div>
  );
};
