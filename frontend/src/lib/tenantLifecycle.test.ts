import { describe, it, expect } from 'vitest';
import { LIFECYCLE_MOVES, lifecycleTargets } from '@/lib/tenantLifecycle';

/**
 * Pins the SPA copy of backend App\Domain\Platform\TenantLifecycle::MOVES, minus `cancelled` (the deletion flow, which
 * needs a second approver) and `deleted` (never set by staff). Change the backend map and this test together.
 */
describe('tenant lifecycle targets', () => {
  it('mirrors the backend canMove map without cancelled and deleted', () => {
    expect(LIFECYCLE_MOVES).toEqual({
      trial: ['active', 'read_only'],
      active: ['past_due'],
      past_due: ['active', 'read_only'],
      read_only: ['active', 'suspended'],
      suspended: ['active'],
      cancelled: ['active'],
      deleted: [],
    });
  });

  it('never offers cancelled or deleted as a target, from any state', () => {
    for (const from of Object.keys(LIFECYCLE_MOVES)) {
      const to = lifecycleTargets(from).map((t) => t.to);
      expect(to).not.toContain('cancelled');
      expect(to).not.toContain('deleted');
    }
  });

  it('labels each target and gives an unknown state no targets', () => {
    expect(lifecycleTargets('trial')).toEqual([
      { to: 'active', label: 'Activate' },
      { to: 'read_only', label: 'Make read-only' },
    ]);
    expect(lifecycleTargets('nonsense')).toEqual([]);
  });
});
