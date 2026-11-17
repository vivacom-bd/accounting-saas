<?php

declare(strict_types=1);

namespace App\Actions\Consolidation;

use App\Actions\Consolidation\Inputs\EntryDraftInput;
use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Organisation\ConsolidationPermissions;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Consolidation\ConsolidationEntry;
use App\Models\Consolidation\ConsolidationEntryLine;
use App\Models\Consolidation\ConsolidationGroup;
use App\Models\Consolidation\ConsolidationGroupAccount;
use App\Models\Consolidation\ConsolidationMember;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use DateTimeImmutable;

/**
 * The consolidation ledger (D16.3): its own entries, with their own gapless numbers and balance check, apart from every
 * company's books. An entry is drafted, then posted by a second person, which gives it its number; a posted entry is
 * never edited, only reversed by a new entry that mirrors it. Nothing here touches journals, balances, sequences or
 * periods of a company, and it does not use the posting engine.
 */
final class ManageConsolidationEntry
{
    /** Kinds that may be recorded by hand; the others come from runs and the acquisition and ownership Actions. */
    public const array MANUAL_KINDS = ['manual', 'unrealised_profit'];

    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
        private readonly ConsolidationAccess $access,
        private readonly ConsolidationRunGuard $guard,
    ) {}

    /** A manual group entry (adjustments the engine cannot derive, such as unrealised profit in inventory). */
    public function draftManual(Actor $actor, EntryDraftInput $in): ConsolidationEntry
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $in) {
            $this->access->require($actor, ConsolidationPermissions::MANAGE, $in->groupId);
            if (! in_array($in->kind, self::MANUAL_KINDS, true)) {
                throw DomainRuleViolation::single('entry_kind_invalid', 'Only manual entries are recorded by hand.');
            }

            return $this->insert($actor, $locks, $audit, $in);
        });
    }

    /**
     * Inserts a draft without the rights check; the calling Action has done its own. Run inside its unit of work.
     */
    public function insert(Actor $actor, LockSet $locks, AuditBuffer $audit, EntryDraftInput $in): ConsolidationEntry
    {
        $locks->document(ConsolidationGroup::query()->findOrFail($in->groupId));
        if ($in->runId === null) {
            $this->guard->assertNoRunAtOrAfter($in->groupId, $in->entryDate, 'A group entry must be dated after the last consolidated period; an earlier correction is made by reversing the run.');
        }
        $accountIds = array_values(array_unique(array_map(fn (array $l): string => $l['account_id'], $in->lines)));
        if (ConsolidationGroupAccount::query()->where('group_id', $in->groupId)->whereIn('id', $accountIds)->count() !== count($accountIds)) {
            throw DomainRuleViolation::single('not_found', 'An entry line names an account that is not in the group chart.');
        }
        $memberIds = array_values(array_unique(array_filter([$in->memberId, ...array_map(fn (array $l): ?string => $l['member_id'] ?? null, $in->lines)], fn (?string $id): bool => $id !== null)));
        if (ConsolidationMember::query()->where('group_id', $in->groupId)->whereIn('id', $memberIds)->count() !== count($memberIds)) {
            throw DomainRuleViolation::single('not_found', 'An entry names a member that is not in the group.');
        }
        $debit = 0;
        $credit = 0;
        foreach ($in->lines as $l) {
            $debit += max($l['net'], 0);
            $credit += max(-$l['net'], 0);
        }
        if (count($in->lines) < 2 || $debit !== $credit || $debit === 0) {
            throw DomainRuleViolation::single('entry_unbalanced', "A consolidation entry needs two or more lines with equal debits and credits (debit {$debit}, credit {$credit}).");
        }

        $entry = new ConsolidationEntry;
        $entry->forceFill([
            'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'group_id' => $in->groupId, 'run_id' => $in->runId, 'status' => 'draft',
            'kind' => $in->kind, 'entry_date' => $in->entryDate, 'member_id' => $in->memberId, 'memo' => $in->memo, 'detail' => $in->detail,
            'total_debit' => $debit, 'total_credit' => $credit, 'created_by' => $actor->id,
        ])->save();
        $n = 0;
        foreach ($in->lines as $l) {
            if ($l['net'] === 0) {
                continue;
            }
            $line = new ConsolidationEntryLine;
            $line->forceFill([
                'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'entry_id' => $entry->id, 'line_no' => ++$n,
                'group_account_id' => $l['account_id'], 'member_id' => $l['member_id'] ?? null,
                'debit' => max($l['net'], 0), 'credit' => max(-$l['net'], 0), 'description' => $l['description'] ?? null,
            ])->save();
        }
        $entry->refresh();
        $audit->add('consolidation_entry.drafted', $entry, after: $entry, context: ['kind' => $in->kind, 'lines' => $n]);

        return $entry;
    }

    /** Posts a draft: a second person, a number from the group's sequence for the year of the entry date. */
    public function approve(Actor $actor, string $entryId): ConsolidationEntry
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $entryId) {
            $seen = ConsolidationEntry::query()->findOrFail($entryId);
            $this->access->require($actor, ConsolidationPermissions::APPROVE, $seen->group_id);
            $locks->document(ConsolidationGroup::query()->findOrFail($seen->group_id));
            $entry = $locks->document($seen);
            if ($entry->run_id !== null) {
                throw DomainRuleViolation::single('entry_run_bound', 'An entry of a run is posted when the run is approved.');
            }

            return $this->post($actor, $locks, $audit, $entry);
        });
    }

    /** Posts a draft inside the caller's unit of work (the rights are checked by the caller). */
    public function post(Actor $actor, LockSet $locks, AuditBuffer $audit, ConsolidationEntry $entry): ConsolidationEntry
    {
        if ($entry->status !== 'draft') {
            throw DomainRuleViolation::single('entry_not_draft', "A {$entry->status} entry cannot be posted.");
        }
        if ($entry->reverses_entry_id === null && $entry->created_by !== null && $entry->created_by === $actor->id) {
            throw DomainRuleViolation::single('self_approval_forbidden', 'A second person must approve an entry you made.');
        }
        $number = $locks->consolidationNumber($entry->group_id, (int) $entry->entry_date->format('Y'));
        $before = $entry->attributesToArray();
        $entry->forceFill([
            'status' => 'posted', 'number' => $number, 'approved_by' => $actor->id, 'posted_at' => $this->clock->now(), 'lock_version' => $entry->lock_version + 1,
        ])->save();
        if ($entry->reverses_entry_id !== null) {
            ConsolidationEntry::query()->whereKey($entry->reverses_entry_id)->update(['status' => 'reversed', 'reversed_by_id' => $entry->id, 'updated_at' => $this->clock->now()]);
        }
        $audit->add('consolidation_entry.posted', $entry, before: $before, after: $entry, context: ['number' => $number]);

        return $entry;
    }

    /** Withdraws a draft; it never had a number, so no number is lost. */
    public function void(Actor $actor, string $entryId): ConsolidationEntry
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $entryId) {
            $seen = ConsolidationEntry::query()->findOrFail($entryId);
            $this->access->require($actor, ConsolidationPermissions::MANAGE, $seen->group_id);
            $locks->document(ConsolidationGroup::query()->findOrFail($seen->group_id));
            $entry = $locks->document($seen);
            if ($entry->status !== 'draft' || $entry->run_id !== null) {
                throw DomainRuleViolation::single('entry_not_voidable', 'Only a draft entry that is not part of a run can be voided.');
            }
            $before = $entry->attributesToArray();
            $entry->forceFill(['status' => 'void', 'lock_version' => $entry->lock_version + 1])->save();
            $audit->add('consolidation_entry.voided', $entry, before: $before, after: $entry);

            return $entry;
        });
    }

    /**
     * Reverses a posted entry with a mirroring entry, posted at once by someone with the approve right. Impairment of
     * goodwill is never reversed (IAS 36.124), and an entry of a run is reversed with its run.
     */
    public function reverse(Actor $actor, string $entryId, string $reason, ?string $date = null): ConsolidationEntry
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $entryId, $reason, $date) {
            $seen = ConsolidationEntry::query()->findOrFail($entryId);
            $this->access->require($actor, ConsolidationPermissions::APPROVE, $seen->group_id);
            $locks->document(ConsolidationGroup::query()->findOrFail($seen->group_id));
            $entry = $locks->document($seen);
            if ($entry->run_id !== null) {
                throw DomainRuleViolation::single('entry_run_bound', 'An entry of a run is reversed by reversing the run.');
            }

            return $this->mirror($actor, $locks, $audit, $entry, $reason, $date);
        });
    }

    /** The mirror of a posted entry, posted at once, inside the caller's unit of work. */
    public function mirror(Actor $actor, LockSet $locks, AuditBuffer $audit, ConsolidationEntry $entry, string $reason, ?string $date = null): ConsolidationEntry
    {
        if ($entry->status !== 'posted') {
            throw DomainRuleViolation::single('entry_not_posted', "A {$entry->status} entry cannot be reversed.");
        }
        if ($entry->kind === 'goodwill_impairment') {
            throw DomainRuleViolation::single('impairment_not_reversible', 'An impairment loss on goodwill is never reversed (IAS 36.124).');
        }
        $day = $date ?? $entry->entry_date->format('Y-m-d');
        $end = $this->guard->lastApprovedEnd($entry->group_id);
        if ($end !== null && $day <= $end && $entry->run_id === null) {
            $day = (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
        }
        $lines = [];
        foreach ($entry->lines()->get() as $l) {
            $lines[] = ['account_id' => $l->group_account_id, 'member_id' => $l->member_id, 'net' => $l->credit - $l->debit, 'description' => $l->description];
        }
        $draft = new EntryDraftInput($entry->group_id, 'reversal', $day, $lines, "Reversal of {$entry->number}: {$reason}", $entry->member_id, $entry->run_id, ['reverses' => $entry->number, 'reason' => $reason]);
        $reversal = $this->insert($actor, $locks, $audit, $draft);
        $reversal->forceFill(['reverses_entry_id' => $entry->id])->save();

        return $this->post($actor, $locks, $audit, $reversal);
    }
}
