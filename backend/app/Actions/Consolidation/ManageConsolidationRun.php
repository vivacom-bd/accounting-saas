<?php

declare(strict_types=1);

namespace App\Actions\Consolidation;

use App\Actions\Consolidation\Inputs\EntryDraftInput;
use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Consolidation\ConsolidationCalculator;
use App\Domain\Organisation\ConsolidationPermissions;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Consolidation\ConsolidationEntry;
use App\Models\Consolidation\ConsolidationGroup;
use App\Models\Consolidation\ConsolidationIcMatch;
use App\Models\Consolidation\ConsolidationMemberOpening;
use App\Models\Consolidation\ConsolidationRun;
use App\Models\Consolidation\ConsolidationRunLine;
use App\Models\Consolidation\ConsolidationRunMember;
use App\Services\Consolidation\RunInputBuilder;
use App\Services\Consolidation\RunStaleness;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/**
 * Consolidation runs (D16.3, plan 3c.29b): compute a period from the members' trial balances (read-only), freeze the
 * worksheet, and post the run's group entries only when a second person approves. A run never writes to a company's books
 * and does not use the posting engine; its own entries live in the consolidation ledger.
 *
 * Lock order (13 §8): the group, then the run, then the entries' numbers (level 3). A run whose member books moved since it
 * read them is stale and cannot be approved.
 */
final class ManageConsolidationRun
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
        private readonly ConsolidationAccess $access,
        private readonly RunInputBuilder $builder,
        private readonly ConsolidationCalculator $calculator,
        private readonly ManageConsolidationEntry $entries,
        private readonly RunStaleness $staleness,
        private readonly ConsolidationRunGuard $guard,
        private readonly RunApprovalChecks $checks,
    ) {}

    public function create(Actor $actor, string $groupId, string $from, string $to): ConsolidationRun
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $groupId, $from, $to) {
            $this->access->require($actor, ConsolidationPermissions::RUN, $groupId);
            $locks->document(ConsolidationGroup::query()->findOrFail($groupId));
            if (ConsolidationRun::query()->where('group_id', $groupId)->whereIn('status', ['computed', 'approved'])->where('period_to', $to)->exists()) {
                throw DomainRuleViolation::single('run_exists', 'This period already has a run; void or reverse it first.');
            }
            if ($to < $from) {
                throw DomainRuleViolation::single('period_invalid', 'The period ends before it starts.');
            }

            $prepared = $this->builder->prepare($groupId, $from, $to);
            $result = $this->calculator->run($prepared->input);
            if (! $result->isBalanced()) {
                throw DomainRuleViolation::single('worksheet_unbalanced', 'The worksheet does not balance; this is a defect, not a data problem.');
            }

            $run = new ConsolidationRun;
            $run->forceFill([
                'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'group_id' => $groupId, 'period_from' => $from, 'period_to' => $to,
                'currency' => $prepared->input->currency, 'status' => 'computed', 'warnings' => $prepared->warnings,
                'summary' => $result->summary + ['members' => count($prepared->members), 'ic_tolerance' => $prepared->input->icTolerance], 'created_by' => $actor->id,
            ])->save();

            foreach ($prepared->members as $memberId => $m) {
                $row = new ConsolidationRunMember;
                $row->forceFill([
                    'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'run_id' => $run->id, 'member_id' => $memberId, 'company_id' => $m['company_id'],
                    'currency' => $m['currency'], 'window_from' => $m['window_from'],
                    'ledger_version' => $m['ledger_version'], 'closing_rate' => $m['closing_rate'], 'average_rate' => $m['average_rate'],
                    'rate_detail' => $m['rate_detail'] + ($m['opening_id'] === null ? [] : ['equity' => ['source' => 'entered_opening', 'opening_id' => $m['opening_id']]]), 'state' => $result->states[$memberId] ?? [],
                ])->save();
            }
            foreach ($result->local as $memberId => $rows) {
                $this->line($actor, $run->id, 'local', $memberId, $rows);
            }
            foreach ($result->translated as $memberId => $rows) {
                $this->line($actor, $run->id, 'translated', $memberId, $rows);
            }
            foreach ($result->cells as $cell) {
                $this->one($actor, $run->id, 'adjustment', $cell['member_id'], $cell['account_id'], $cell['net'], $cell['source'], $cell['entry_id']);
            }
            $this->line($actor, $run->id, 'consolidated', null, $result->consolidated);
            foreach ($result->matches as $m) {
                $match = new ConsolidationIcMatch;
                $match->forceFill([
                    'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'run_id' => $run->id, 'member_a_id' => $m['member_a'], 'member_b_id' => $m['member_b'],
                    'ic_class' => $m['class'], 'side_a' => $m['side_a'], 'side_b' => $m['side_b'], 'difference' => $m['difference'], 'to_reserve' => $m['to_reserve'], 'recognised_before' => $m['recognised_before'], 'matched' => $m['matched'],
                    'cause' => $m['cause'], 'exceeds_tolerance' => $m['exceeds_tolerance'], 'detail' => $m['detail'],
                ])->save();
            }

            foreach ($result->proposed as $p) {
                $lines = array_map(fn (array $l): array => ['account_id' => $l['account_id'], 'member_id' => $l['member_id'], 'net' => $l['net']], $p['lines']);
                $this->entries->insert($actor, $locks, $audit, new EntryDraftInput($groupId, $p['kind'], $to, $lines, $p['memo'], $p['member_id'], $run->id));
            }
            $run->refresh();
            $audit->add('consolidation_run.computed', $run, after: $run, context: [
                'warnings' => count($prepared->warnings), 'entries' => count($result->proposed),
                'ic_differences_over_tolerance' => count(array_filter($result->matches, fn (array $m): bool => $m['exceeds_tolerance'])),
            ]);

            return $run;
        });
    }

    /** A second person approves: the run must be current, and its entries post with their numbers. */
    public function approve(Actor $actor, string $runId): ConsolidationRun
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $runId) {
            $seen = ConsolidationRun::query()->findOrFail($runId);
            $this->access->require($actor, ConsolidationPermissions::APPROVE, $seen->group_id);
            $locks->document(ConsolidationGroup::query()->findOrFail($seen->group_id));
            // The entered openings the run stands on (rank 79) lock before the run itself (80); approval locks them for good.
            $openingIds = ConsolidationRunMember::query()->where('run_id', $seen->id)->get()
                ->map(fn (ConsolidationRunMember $m): ?string => isset($m->rate_detail['equity']['opening_id']) && is_string($m->rate_detail['equity']['opening_id']) ? $m->rate_detail['equity']['opening_id'] : null)
                ->filter()->sort()->values();
            $openings = $openingIds->map(fn (string $id): ConsolidationMemberOpening => $locks->document(ConsolidationMemberOpening::query()->findOrFail($id)));
            $run = $locks->document($seen);
            if ($run->status !== 'computed') {
                throw DomainRuleViolation::single('run_not_computed', "A {$run->status} run cannot be approved.");
            }
            if ($run->created_by !== null && $run->created_by === $actor->id) {
                throw DomainRuleViolation::single('self_approval_forbidden', 'A second person must approve a run you computed.');
            }
            $moved = $this->staleness->movedMembers($run);
            if ($moved !== []) {
                throw DomainRuleViolation::single('consolidation_run_stale', 'A member\'s books changed after this run read them. Void the run and compute it again.');
            }
            $end = $this->guard->lastApprovedEnd($run->group_id);
            if ($end !== null && $end >= $run->period_from->format('Y-m-d')) {
                throw DomainRuleViolation::single('period_already_consolidated', 'An approved run already covers part of this period.');
            }
            $this->checks->assertApprovable($run);
            $open = ConsolidationIcMatch::query()->where('run_id', $run->id)->where('exceeds_tolerance', true)->whereNull('accepted_by')->count();
            if ($open > 0) {
                throw DomainRuleViolation::single('ic_difference_unaccepted', "{$open} intercompany difference(s) exceed the group's tolerance; a second person must accept each with a reason before the run is approved.");
            }

            // Every entry is locked (level 2, by id) before the first number is taken (level 3).
            $entries = ConsolidationEntry::query()->where('run_id', $run->id)->where('status', 'draft')->orderBy('id')->get()
                ->map(fn (ConsolidationEntry $e): ConsolidationEntry => $locks->document($e));
            foreach ($entries as $entry) {
                $this->entries->post($actor, $locks, $audit, $entry);
            }
            $before = $run->attributesToArray();
            $run->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => $this->clock->now(), 'lock_version' => $run->lock_version + 1])->save();
            // After the run is approved: the trigger lets an opening lock only to an approved run.
            foreach ($openings as $opening) {
                $was = $opening->attributesToArray();
                $opening->forceFill(['locked_by_run_id' => $run->id, 'locked_at' => $this->clock->now(), 'lock_version' => $opening->lock_version + 1])->save();
                $audit->add('consolidation_opening.locked', $opening, before: $was, after: $opening, context: ['run_id' => $run->id]);
            }
            $audit->add('consolidation_run.approved', $run, before: $before, after: $run, context: ['entries' => $entries->count()]);

            return $run;
        });
    }

    /**
     * A second person accepts an intercompany difference above the group's tolerance, with a reason, so it no longer blocks
     * approval (D16.7). The pair is eliminated in full either way; the difference is in the group's intercompany differences account.
     */
    public function acceptDifference(Actor $actor, string $runId, string $matchId, string $reason): ConsolidationIcMatch
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $runId, $matchId, $reason) {
            $seen = ConsolidationRun::query()->findOrFail($runId);
            $this->access->require($actor, ConsolidationPermissions::APPROVE, $seen->group_id);
            $locks->document(ConsolidationGroup::query()->findOrFail($seen->group_id));
            $run = $locks->document($seen);
            $match = ConsolidationIcMatch::query()->where('run_id', $run->id)->findOrFail($matchId);
            if ($run->status !== 'computed') {
                throw DomainRuleViolation::single('run_not_computed', "A difference on a {$run->status} run cannot be accepted.");
            }
            if ($run->created_by !== null && $run->created_by === $actor->id) {
                throw DomainRuleViolation::single('self_approval_forbidden', 'A second person must accept a difference on a run you computed.');
            }
            if (! $match->exceeds_tolerance) {
                throw DomainRuleViolation::single('ic_difference_within_tolerance', 'This difference is within the group\'s tolerance and needs no acceptance.');
            }
            if ($match->accepted_by !== null) {
                throw DomainRuleViolation::single('ic_difference_accepted', 'This difference is already accepted.');
            }
            if (trim($reason) === '') {
                throw DomainRuleViolation::single('reason_required', 'Give a reason for accepting the difference.');
            }
            $before = $match->attributesToArray();
            $match->forceFill(['accepted_by' => $actor->id, 'accepted_at' => $this->clock->now(), 'reason' => $reason])->save();
            $audit->add('consolidation_ic_match.accepted', $match, before: $before, after: $match, context: ['run_id' => $run->id, 'difference' => $match->difference]);

            return $match;
        });
    }

    public function void(Actor $actor, string $runId, ?string $reason = null): ConsolidationRun
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $runId, $reason) {
            $seen = ConsolidationRun::query()->findOrFail($runId);
            $this->access->require($actor, ConsolidationPermissions::RUN, $seen->group_id);
            $locks->document(ConsolidationGroup::query()->findOrFail($seen->group_id));
            $run = $locks->document($seen);
            if ($run->status !== 'computed') {
                throw DomainRuleViolation::single('run_not_voidable', 'Only a computed run can be voided; an approved run is reversed.');
            }
            ConsolidationEntry::query()->where('run_id', $run->id)->where('status', 'draft')->update(['status' => 'void', 'updated_at' => $this->clock->now()]);
            $before = $run->attributesToArray();
            $run->forceFill(['status' => 'void', 'reason' => $reason, 'lock_version' => $run->lock_version + 1])->save();
            $audit->add('consolidation_run.voided', $run, before: $before, after: $run);

            return $run;
        });
    }

    /**
     * Undoes an approved run by reversing each of its entries (corrections are reversals). Only the latest approved run of a
     * group can be reversed, because later runs stand on it.
     */
    public function reverse(Actor $actor, string $runId, string $reason): ConsolidationRun
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $runId, $reason) {
            $seen = ConsolidationRun::query()->findOrFail($runId);
            $this->access->require($actor, ConsolidationPermissions::APPROVE, $seen->group_id);
            $locks->document(ConsolidationGroup::query()->findOrFail($seen->group_id));
            // Openings this run locked (rank 79) lock before the run (80); they unlock once it is reversed.
            $openings = ConsolidationMemberOpening::query()->where('locked_by_run_id', $seen->id)->orderBy('id')->get()
                ->map(fn (ConsolidationMemberOpening $o): ConsolidationMemberOpening => $locks->document($o));
            $run = $locks->document($seen);
            if ($run->status !== 'approved') {
                throw DomainRuleViolation::single('run_not_approved', "A {$run->status} run cannot be reversed.");
            }
            if (ConsolidationRun::query()->where('group_id', $run->group_id)->where('status', 'approved')->where('period_to', '>', $run->period_to->format('Y-m-d'))->exists()) {
                throw DomainRuleViolation::single('run_not_latest', 'A later run stands on this one; reverse the later run first.');
            }
            $entries = ConsolidationEntry::query()->where('run_id', $run->id)->where('status', 'posted')->whereNull('reverses_entry_id')->orderBy('id')->get()
                ->map(fn (ConsolidationEntry $e): ConsolidationEntry => $locks->document($e));
            foreach ($entries as $entry) {
                $this->entries->mirror($actor, $locks, $audit, $entry, $reason);
            }
            $before = $run->attributesToArray();
            $run->forceFill(['status' => 'reversed', 'reversed_by' => $actor->id, 'reversed_at' => $this->clock->now(), 'reason' => $reason, 'lock_version' => $run->lock_version + 1])->save();
            $audit->add('consolidation_run.reversed', $run, before: $before, after: $run, context: ['reason' => $reason]);
            foreach ($openings as $opening) {
                if ($this->usedByAnotherApprovedRun($opening, $run->id)) {
                    continue;
                }
                $was = $opening->attributesToArray();
                $opening->forceFill(['locked_by_run_id' => null, 'locked_at' => null, 'lock_version' => $opening->lock_version + 1])->save();
                $audit->add('consolidation_opening.unlocked', $opening, before: $was, after: $opening, context: ['run_id' => $run->id]);
            }

            return $run;
        });
    }

    /** Whether an approved run other than $runId starts from this opening (in practice none: later runs start from the previous run). */
    private function usedByAnotherApprovedRun(ConsolidationMemberOpening $opening, string $runId): bool
    {
        return ConsolidationRunMember::query()->where('member_id', $opening->member_id)->where('run_id', '<>', $runId)
            ->whereIn('run_id', ConsolidationRun::query()->where('group_id', $opening->group_id)->where('status', 'approved')->select('id'))
            ->get()->contains(fn (ConsolidationRunMember $m): bool => ($m->rate_detail['equity']['opening_id'] ?? null) === $opening->id);
    }

    /** @param array<string, int> $rows */
    private function line(Actor $actor, string $runId, string $kind, ?string $memberId, array $rows): void
    {
        foreach ($rows as $account => $amount) {
            $this->one($actor, $runId, $kind, $memberId, (string) $account, $amount, null, null);
        }
    }

    private function one(Actor $actor, string $runId, string $kind, ?string $memberId, string $account, int $amount, ?string $source, ?string $entryId): void
    {
        $line = new ConsolidationRunLine;
        $line->forceFill([
            'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'run_id' => $runId, 'member_id' => $memberId, 'group_account_id' => $account,
            'kind' => $kind, 'source' => $source, 'entry_id' => $entryId, 'amount' => $amount,
        ])->save();
    }
}
