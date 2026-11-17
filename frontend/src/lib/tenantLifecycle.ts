/**
 * Which lifecycle states staff may move a tenant to by hand. A copy of backend App\Domain\Platform\TenantLifecycle::MOVES
 * (the source of truth, `canMove`) without `cancelled` (closing a tenant is the deletion flow with a second approver:
 * `POST /tenants/{id}/deletion`, and `lifecycle {to: cancelled}` answers 422 use_deletion_flow) and without `deleted`
 * (never set by staff). Pinned by tenantLifecycle.test.ts; the API still re-checks every move.
 */
export type LifecycleStatus = 'trial' | 'active' | 'past_due' | 'read_only' | 'suspended' | 'cancelled' | 'deleted';

export const LIFECYCLE_MOVES: Record<LifecycleStatus, LifecycleStatus[]> = {
  trial: ['active', 'read_only'],
  active: ['past_due'],
  past_due: ['active', 'read_only'],
  read_only: ['active', 'suspended'],
  suspended: ['active'],
  cancelled: ['active'],
  deleted: [],
};

export const LIFECYCLE_STATUSES: LifecycleStatus[] = ['trial', 'active', 'past_due', 'read_only', 'suspended', 'cancelled', 'deleted'];

const LABELS: Record<string, string> = {
  active: 'Activate',
  past_due: 'Mark past due',
  read_only: 'Make read-only',
  suspended: 'Suspend',
};

export const LIFECYCLE_NAMES: Record<LifecycleStatus, string> = {
  trial: 'Trial',
  active: 'Active',
  past_due: 'Past due',
  read_only: 'Read-only',
  suspended: 'Suspended',
  cancelled: 'Cancelled',
  deleted: 'Deleted',
};

export interface LifecycleTarget {
  to: LifecycleStatus;
  label: string;
}

export function lifecycleTargets(from: string): LifecycleTarget[] {
  const moves = (LIFECYCLE_MOVES as Record<string, LifecycleStatus[] | undefined>)[from] ?? [];
  return moves.map((to) => ({ to, label: LABELS[to] ?? to }));
}
