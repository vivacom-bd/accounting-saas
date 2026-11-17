<?php

declare(strict_types=1);

namespace App\Actions\Consolidation;

use App\Contracts\IdGenerator;
use App\Domain\Consolidation\Intercompany;
use App\Domain\Fx\MonetaryAccounts;
use App\Domain\Organisation\ConsolidationPermissions;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Consolidation\ConsolidationAccountMap;
use App\Models\Consolidation\ConsolidationGroup;
use App\Models\Consolidation\ConsolidationGroupAccount;
use App\Models\Consolidation\ConsolidationIcContact;
use App\Models\Consolidation\ConsolidationMember;
use App\Models\Ledger\Account;
use App\Models\Trade\Contact;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/**
 * How a member's intragroup items are recognised (D16.7, plan 3c.29c): a mapped account is flagged intercompany with a
 * class, and its counterparty is either one member for the whole account or, line by line, the member a contact stands
 * for. Group configuration only: nothing in the member's books changes.
 *
 * A loan must be a monetary item in the member's books (IAS 21.16), so it is revalued at the closing rate whatever its
 * subtype ("foreign-currency intragroup loans remain monetary and revalue in the member's books"); one in a non-monetary
 * subtype is refused until the company classes it monetary. Only a loan can be designated part of the net investment.
 */
final class ManageIntercompany
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly ConsolidationAccess $access,
    ) {}

    /**
     * @param  list<array{account_id: string, ic_class: string|null, counterparty_member_id?: string|null, net_investment?: bool}>  $accounts
     * @param  list<array{contact_id: string, counterparty_member_id: string|null}>  $contacts
     */
    public function set(Actor $actor, string $memberId, array $accounts, array $contacts): void
    {
        $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $memberId, $accounts, $contacts) {
            $member = ConsolidationMember::query()->findOrFail($memberId);
            $this->access->require($actor, ConsolidationPermissions::MANAGE, $member->group_id, [$member->company_id]);
            $locks->document(ConsolidationGroup::query()->findOrFail($member->group_id));

            foreach ($accounts as $row) {
                $map = ConsolidationAccountMap::query()->where('member_id', $member->id)->where('account_id', $row['account_id'])->first()
                    ?? throw DomainRuleViolation::single('ic_account_unmapped', 'Map the account to a group account before flagging it intercompany.');
                $class = $row['ic_class'];
                $counterparty = $class === null ? null : ($row['counterparty_member_id'] ?? null);
                $netInvestment = $class !== null && ($row['net_investment'] ?? false);
                if ($class !== null) {
                    $this->checkClass($member, $map, $class, $counterparty, $netInvestment);
                }
                $before = $map->attributesToArray();
                $map->forceFill(['ic_class' => $class, 'counterparty_member_id' => $counterparty, 'net_investment' => $netInvestment])->save();
                if ($map->wasChanged()) {
                    $audit->add('consolidation_map.intercompany_set', $map, before: $before, after: $map);
                }
            }

            foreach ($contacts as $row) {
                Contact::query()->where('company_id', $member->company_id)->find($row['contact_id'])
                    ?? throw DomainRuleViolation::single('not_found', 'A contact is not in the member company.');
                $existing = ConsolidationIcContact::query()->where('member_id', $member->id)->where('contact_id', $row['contact_id'])->first();
                $counterparty = $row['counterparty_member_id'];
                if ($counterparty === null) {
                    if ($existing !== null) {
                        $audit->add('consolidation_ic_contact.removed', $existing, before: $existing->attributesToArray());
                        $existing->delete();
                    }

                    continue;
                }
                $this->checkCounterparty($member, $counterparty);
                $link = $existing ?? new ConsolidationIcContact;
                $before = $link->exists ? $link->attributesToArray() : null;
                $link->forceFill($link->exists ? ['counterparty_member_id' => $counterparty] : [
                    'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $member->company_id, 'group_id' => $member->group_id,
                    'member_id' => $member->id, 'contact_id' => $row['contact_id'], 'counterparty_member_id' => $counterparty, 'created_by' => $actor->id,
                ])->save();
                $audit->add('consolidation_ic_contact.set', $link, before: $before, after: $link);
            }
        });
    }

    private function checkClass(ConsolidationMember $member, ConsolidationAccountMap $map, string $class, ?string $counterparty, bool $netInvestment): void
    {
        if (! in_array($class, Intercompany::CLASSES, true)) {
            throw DomainRuleViolation::single('ic_class_invalid', "Unknown intercompany class {$class}.");
        }
        $target = ConsolidationGroupAccount::query()->findOrFail($map->group_account_id);
        if (! in_array($target->account_type, Intercompany::TYPES[$class], true)) {
            throw DomainRuleViolation::single('ic_class_type_mismatch', "A {$target->account_type} account cannot hold intragroup {$class} items.");
        }
        if ($counterparty !== null) {
            $this->checkCounterparty($member, $counterparty);
        }
        if ($netInvestment && $class !== Intercompany::LOAN) {
            throw DomainRuleViolation::single('net_investment_not_loan', 'Only a loan can be designated part of the net investment.');
        }
        if ($class === Intercompany::LOAN) {
            $account = Account::query()->findOrFail($map->account_id);
            if (! MonetaryAccounts::isMonetary($account->account_type, $account->control_type, $account->account_subtype, $account->reporting_tags, $account->ifrs_element)) {
                throw DomainRuleViolation::single('ic_loan_not_monetary', "Account {$account->code} is treated as non-monetary, so it would not be revalued; class it monetary first (an intragroup loan is a monetary item, IAS 21.16).");
            }
        }
    }

    private function checkCounterparty(ConsolidationMember $member, string $counterparty): void
    {
        if ($counterparty === $member->id || ! ConsolidationMember::query()->where('group_id', $member->group_id)->whereKey($counterparty)->exists()) {
            throw DomainRuleViolation::single('ic_counterparty_invalid', 'The counterparty must be another member of the same group.');
        }
    }
}
