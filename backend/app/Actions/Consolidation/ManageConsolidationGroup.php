<?php

declare(strict_types=1);

namespace App\Actions\Consolidation;

use App\Contracts\IdGenerator;
use App\Domain\Consolidation\GroupRoles;
use App\Domain\Organisation\ConsolidationPermissions;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Consolidation\ConsolidationAccountMap;
use App\Models\Consolidation\ConsolidationGroup;
use App\Models\Consolidation\ConsolidationGroupAccount;
use App\Models\Consolidation\ConsolidationMember;
use App\Models\Consolidation\ConsolidationOwnership;
use App\Models\Consolidation\ConsolidationRateOverride;
use App\Models\Ledger\Account;
use App\Models\Organisation\Company;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/**
 * Groups, members, dated ownership, the group chart and the account mapping (D16.1, D16.2, plan 3c.29a). None of it
 * writes to a company's books. Every method checks the organisation-level right and view rights in the companies it
 * reads, and records an audit event.
 *
 * The presentation currency must equal the parent company's base currency: the parent's own books are consolidated
 * untranslated, and the investment it holds is eliminated at the acquisition rate (a parent reporting in another
 * currency than the group would need translation of the investment, which this phase does not do).
 */
final class ManageConsolidationGroup
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly ConsolidationAccess $access,
        private readonly ConsolidationRunGuard $guard,
    ) {}

    public function create(Actor $actor, string $name, string $currency, string $parentCompanyId, int $icTolerance = 0): ConsolidationGroup
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $name, $currency, $parentCompanyId, $icTolerance) {
            $this->access->require($actor, ConsolidationPermissions::MANAGE, companyIds: [$parentCompanyId]);
            $parent = Company::query()->find($parentCompanyId) ?? throw DomainRuleViolation::single('not_found', 'The parent company does not exist.');
            if ($parent->base_currency !== $currency) {
                throw DomainRuleViolation::single('group_currency_mismatch', "The presentation currency must be the parent company's base currency ({$parent->base_currency}).");
            }
            if (ConsolidationGroup::query()->where('name', $name)->exists()) {
                throw DomainRuleViolation::single('group_name_taken', 'A group with this name already exists.');
            }

            $group = new ConsolidationGroup;
            $group->forceFill([
                'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'name' => $name, 'presentation_currency' => $currency,
                'parent_company_id' => $parentCompanyId, 'ic_tolerance' => $icTolerance, 'created_by' => $actor->id,
            ])->save();
            $this->member($actor, $group->id, $parentCompanyId, 'parent', null);
            foreach (GroupRoles::DEFAULTS as $d) {
                $this->insertAccount($actor, $group->id, $d['code'], $d['name'], $d['type'], $d['subtype'], $d['role']);
            }
            $group->refresh();
            $audit->add('consolidation_group.created', $group, after: $group);

            return $group;
        });
    }

    public function update(Actor $actor, string $groupId, string $name, int $icTolerance, int $version): ConsolidationGroup
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $groupId, $name, $icTolerance, $version) {
            $this->access->require($actor, ConsolidationPermissions::MANAGE, $groupId);
            $group = $locks->document(ConsolidationGroup::query()->findOrFail($groupId), $version);
            $before = $group->attributesToArray();
            $group->forceFill(['name' => $name, 'ic_tolerance' => $icTolerance, 'lock_version' => $group->lock_version + 1])->save();
            $group->refresh();
            $audit->add('consolidation_group.updated', $group, before: $before, after: $group);

            return $group;
        });
    }

    /** Adds a subsidiary with its acquisition date and the parent's share from then on. */
    public function addSubsidiary(Actor $actor, string $groupId, string $companyId, string $acquisitionDate, int $percentBp): ConsolidationMember
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $groupId, $companyId, $acquisitionDate, $percentBp) {
            $this->access->require($actor, ConsolidationPermissions::MANAGE, $groupId, [$companyId]);
            $group = $locks->document(ConsolidationGroup::query()->findOrFail($groupId));
            if ($percentBp < 1 || $percentBp > 10000) {
                throw DomainRuleViolation::single('ownership_invalid', 'Ownership is 1 to 10000 basis points.');
            }
            if (! Company::query()->whereKey($companyId)->exists()) {
                throw DomainRuleViolation::single('not_found', 'The company does not exist.');
            }
            if (ConsolidationMember::query()->where('group_id', $group->id)->where('company_id', $companyId)->exists()) {
                throw DomainRuleViolation::single('member_exists', 'This company is already in the group.');
            }
            $this->guard->assertNoRunAtOrAfter($group->id, $acquisitionDate, 'A subsidiary cannot be acquired on or before the end of a period already consolidated.');

            $member = $this->member($actor, $group->id, $companyId, 'subsidiary', $acquisitionDate);
            $this->ownership($actor, $member, $acquisitionDate, $percentBp, 'Acquisition');
            $audit->add('consolidation_member.added', $member, after: $member, context: ['percent_bp' => $percentBp]);

            return $member;
        });
    }

    /** A later row of the history. Rows at or before the end of an approved run are fixed. */
    public function setOwnership(Actor $actor, string $memberId, string $effectiveOn, int $percentBp, ?string $note): ConsolidationOwnership
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $memberId, $effectiveOn, $percentBp, $note) {
            $member = ConsolidationMember::query()->findOrFail($memberId);
            $this->access->require($actor, ConsolidationPermissions::MANAGE, $member->group_id);
            $locks->document(ConsolidationGroup::query()->findOrFail($member->group_id));
            if ($member->isParent()) {
                throw DomainRuleViolation::single('ownership_parent', 'The parent has no ownership percentage.');
            }
            if ($percentBp < 0 || $percentBp > 10000) {
                throw DomainRuleViolation::single('ownership_invalid', 'Ownership is 0 to 10000 basis points.');
            }
            if ($effectiveOn < $member->acquisition_date?->format('Y-m-d')) {
                throw DomainRuleViolation::single('ownership_before_acquisition', 'Ownership cannot change before the acquisition date.');
            }
            $this->guard->assertNoRunAfter($member->group_id, $effectiveOn, 'Ownership dated within a period already consolidated is fixed; the change belongs to an ownership-change entry after that run.');
            $row = $this->ownership($actor, $member, $effectiveOn, $percentBp, $note);
            $audit->add('consolidation_ownership.set', $row, after: $row);

            return $row;
        });
    }

    public function saveAccount(Actor $actor, string $groupId, ?string $accountId, string $code, string $name, string $type, string $subtype): ConsolidationGroupAccount
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $groupId, $accountId, $code, $name, $type, $subtype) {
            $this->access->require($actor, ConsolidationPermissions::MANAGE, $groupId);
            $locks->document(ConsolidationGroup::query()->findOrFail($groupId));
            if ($accountId === null) {
                if (ConsolidationGroupAccount::query()->where('group_id', $groupId)->where('code', $code)->exists()) {
                    throw DomainRuleViolation::single('group_account_code_taken', 'This code is already used in the group chart.');
                }
                $account = $this->insertAccount($actor, $groupId, $code, $name, $type, $subtype, null);
                $audit->add('consolidation_account.created', $account, after: $account);

                return $account;
            }
            $account = ConsolidationGroupAccount::query()->where('group_id', $groupId)->findOrFail($accountId);
            if ($account->role !== null && $account->account_type !== $type) {
                throw DomainRuleViolation::single('group_account_role_locked', 'The type of an account the engine posts to cannot change.');
            }
            if ($account->account_type !== $type && ConsolidationAccountMap::query()->where('group_account_id', $account->id)->exists()) {
                throw DomainRuleViolation::single('group_account_in_use', 'An account with mappings cannot change type.');
            }
            $before = $account->attributesToArray();
            $account->forceFill(['code' => $code, 'name' => $name, 'account_type' => $type, 'account_subtype' => $subtype])->save();
            $audit->add('consolidation_account.updated', $account, before: $before, after: $account);

            return $account;
        });
    }

    /**
     * Maps member accounts to group accounts (replacing earlier mappings of the same accounts). An account maps to a
     * group account of the same type.
     *
     * @param  list<array{account_id: string, group_account_id: string}>  $pairs
     * @return list<ConsolidationAccountMap>
     */
    public function mapAccounts(Actor $actor, string $memberId, array $pairs): array
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $memberId, $pairs) {
            $member = ConsolidationMember::query()->findOrFail($memberId);
            $this->access->require($actor, ConsolidationPermissions::MANAGE, $member->group_id, [$member->company_id]);
            $locks->document(ConsolidationGroup::query()->findOrFail($member->group_id));
            $out = [];
            foreach ($pairs as $pair) {
                $account = Account::query()->where('company_id', $member->company_id)->find($pair['account_id'])
                    ?? throw DomainRuleViolation::single('not_found', 'An account is not in the member company.');
                $target = ConsolidationGroupAccount::query()->where('group_id', $member->group_id)->find($pair['group_account_id'])
                    ?? throw DomainRuleViolation::single('not_found', 'A group account does not exist.');
                if ($account->account_type !== $target->account_type) {
                    throw DomainRuleViolation::single('mapping_type_mismatch', "Account {$account->code} is {$account->account_type} but {$target->code} is {$target->account_type}.");
                }
                $map = ConsolidationAccountMap::query()->where('member_id', $member->id)->where('account_id', $account->id)->first() ?? new ConsolidationAccountMap;
                $before = $map->exists ? $map->attributesToArray() : null;
                $map->forceFill($map->exists ? ['group_account_id' => $target->id] : [
                    'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $member->company_id, 'group_id' => $member->group_id,
                    'member_id' => $member->id, 'account_id' => $account->id, 'group_account_id' => $target->id, 'created_by' => $actor->id,
                ])->save();
                $audit->add('consolidation_map.set', $map, before: $before, after: $map);
                $out[] = $map;
            }

            return $out;
        });
    }

    /** The average rate for a currency and run period where the default (the mean of the rate in force on each day) does not approximate the transaction rates (D16.4). It applies to a run of exactly that period. */
    public function setRateOverride(Actor $actor, string $groupId, string $currency, string $periodFrom, string $periodTo, string $rate, string $reason): ConsolidationRateOverride
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $groupId, $currency, $periodFrom, $periodTo, $rate, $reason) {
            $this->access->require($actor, ConsolidationPermissions::MANAGE, $groupId);
            $group = $locks->document(ConsolidationGroup::query()->findOrFail($groupId));
            if ($currency === $group->presentation_currency) {
                throw DomainRuleViolation::single('rate_override_invalid', 'The presentation currency needs no rate.');
            }
            $this->guard->assertNoRunAtOrAfter($groupId, $periodFrom, 'A rate cannot be set for a period already consolidated.');
            $row = ConsolidationRateOverride::query()->where('group_id', $groupId)->where('currency', $currency)->where('period_from', $periodFrom)->where('period_to', $periodTo)->first() ?? new ConsolidationRateOverride;
            $before = $row->exists ? $row->attributesToArray() : null;
            $row->forceFill([
                'id' => $row->exists ? $row->id : $this->ids->next(), 'tenant_id' => $actor->tenantId, 'group_id' => $groupId, 'currency' => $currency,
                'period_from' => $periodFrom, 'period_to' => $periodTo, 'rate' => $rate, 'reason' => $reason, 'created_by' => $actor->id,
            ])->save();
            $audit->add('consolidation_rate.set', $row, before: $before, after: $row);

            return $row;
        });
    }

    private function member(Actor $actor, string $groupId, string $companyId, string $role, ?string $acquisitionDate): ConsolidationMember
    {
        $member = new ConsolidationMember;
        $member->forceFill([
            'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'group_id' => $groupId, 'company_id' => $companyId,
            'role' => $role, 'acquisition_date' => $acquisitionDate, 'created_by' => $actor->id,
        ])->save();

        return $member;
    }

    private function ownership(Actor $actor, ConsolidationMember $member, string $effectiveOn, int $percentBp, ?string $note): ConsolidationOwnership
    {
        $row = ConsolidationOwnership::query()->where('member_id', $member->id)->where('effective_on', $effectiveOn)->first() ?? new ConsolidationOwnership;
        $row->forceFill([
            'id' => $row->exists ? $row->id : $this->ids->next(), 'tenant_id' => $actor->tenantId, 'group_id' => $member->group_id,
            'member_id' => $member->id, 'effective_on' => $effectiveOn, 'percent_bp' => $percentBp, 'note' => $note, 'created_by' => $actor->id,
        ])->save();

        return $row;
    }

    private function insertAccount(Actor $actor, string $groupId, string $code, string $name, string $type, string $subtype, ?string $role): ConsolidationGroupAccount
    {
        $account = new ConsolidationGroupAccount;
        $account->forceFill([
            'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'group_id' => $groupId, 'code' => $code, 'name' => $name,
            'account_type' => $type, 'account_subtype' => $subtype, 'role' => $role,
        ])->save();

        return $account;
    }
}
