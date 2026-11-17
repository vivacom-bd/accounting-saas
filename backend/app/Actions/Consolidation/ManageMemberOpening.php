<?php

declare(strict_types=1);

namespace App\Actions\Consolidation;

use App\Contracts\IdGenerator;
use App\Domain\Consolidation\GroupRoles;
use App\Domain\Organisation\ConsolidationPermissions;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Consolidation\ConsolidationGroup;
use App\Models\Consolidation\ConsolidationGroupAccount;
use App\Models\Consolidation\ConsolidationMember;
use App\Models\Consolidation\ConsolidationMemberOpening;
use App\Models\Consolidation\ConsolidationRun;
use App\Models\Consolidation\ConsolidationRunMember;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/**
 * Opening translated equity of a foreign subsidiary the group held before its first run in Ledgerly (D16.4, owner's
 * decision 2026-11-17). The accountant enters, from the last consolidation done elsewhere and as of its date, the equity
 * per group equity account at historical rates and the translation reserve, in the presentation currency, with a reason.
 * The first run then starts the day after that date. Editable until a run using it is approved, then locked (also by a
 * trigger) until that run is reversed. Whether it covers the books at its date is checked when a run reads it, since the books may still move.
 *
 * Lock order (13 §8): the group (rank 78), then the opening (79).
 */
final class ManageMemberOpening
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly ConsolidationAccess $access,
        private readonly ConsolidationRunGuard $guard,
    ) {}

    /** @param array<string, int> $equity group equity account id → net amount (credit negative) */
    public function set(Actor $actor, string $memberId, string $asOf, array $equity, int $translationReserve, string $reason, ?int $version = null): ConsolidationMemberOpening
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $memberId, $asOf, $equity, $translationReserve, $reason, $version) {
            $member = ConsolidationMember::query()->with('company')->findOrFail($memberId);
            $this->access->require($actor, ConsolidationPermissions::MANAGE, $member->group_id);
            $group = $locks->document(ConsolidationGroup::query()->findOrFail($member->group_id));
            $existing = ConsolidationMemberOpening::query()->where('member_id', $member->id)->first();
            $row = $existing === null ? new ConsolidationMemberOpening : $locks->document($existing, $version);
            if ($row->exists && $row->locked_by_run_id !== null) {
                throw DomainRuleViolation::single('opening_locked', 'This opening is locked: an approved run stands on it. Reverse that run before anything can change, and correct later periods with group entries.');
            }

            if ($member->isParent() || $member->company?->base_currency === $group->presentation_currency) {
                throw DomainRuleViolation::single('opening_not_needed', 'Only a subsidiary whose currency differs from the presentation currency needs an opening translated equity.');
            }
            if ($member->acquisition_date !== null && $asOf < $member->acquisition_date->format('Y-m-d')) {
                throw DomainRuleViolation::single('opening_before_acquisition', 'The opening cannot be dated before the acquisition date.');
            }
            if (ConsolidationRunMember::query()->where('member_id', $member->id)->whereIn('run_id', ConsolidationRun::query()->where('status', 'approved')->select('id'))->exists()) {
                throw DomainRuleViolation::single('opening_not_needed', 'The subsidiary is already consolidated; its next run starts from the previous run.');
            }
            $this->guard->assertNoRunAtOrAfter($group->id, $asOf, 'The opening must be dated after the last consolidated period.');
            $accounts = ConsolidationGroupAccount::query()->where('group_id', $group->id)->whereIn('id', array_keys($equity))->get()->keyBy('id');
            foreach ($equity as $accountId => $amount) {
                $a = $accounts->get($accountId);
                if ($a === null || $a->account_type !== 'equity' || $a->role === GroupRoles::TRANSLATION_RESERVE) {
                    throw DomainRuleViolation::single('opening_account_invalid', 'Each amount must be on an equity account of the group chart other than the translation reserve, which is entered on its own.');
                }
            }

            $before = $row->exists ? $row->attributesToArray() : null;
            $row->forceFill([
                'id' => $row->exists ? $row->id : $this->ids->next(), 'tenant_id' => $actor->tenantId, 'group_id' => $group->id, 'member_id' => $member->id,
                'as_of' => $asOf, 'equity' => array_filter($equity, fn (int $v): bool => $v !== 0), 'translation_reserve' => $translationReserve, 'reason' => $reason,
                'created_by' => $row->exists ? $row->created_by : $actor->id, 'updated_by' => $actor->id, 'lock_version' => $row->exists ? $row->lock_version + 1 : 0,
            ])->save();
            $audit->add('consolidation_opening.set', $row, before: $before, after: $row, context: ['reason' => $reason]);

            return $row;
        });
    }
}
