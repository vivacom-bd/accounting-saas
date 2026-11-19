/** Platform announcements that apply to the current organisation (plan Task 15): any signed-in member may read them. */
import { http } from '../http';

export interface Announcement {
  id: string;
  title: string;
  body: string;
  kind: 'info' | 'warning' | 'maintenance';
  starts_at: string;
  ends_at: string | null;
}

export async function fetchAnnouncements(): Promise<Announcement[]> {
  const res = await http.send<{ data: Announcement[] }>('GET', '/v1/announcements');
  return res.data;
}
