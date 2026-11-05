<?php

declare(strict_types=1);

namespace App\Actions\Tax;

use App\Actions\Ledger\SetupGuard;
use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Organisation\Company;
use App\Models\Tax\TaxPeriod;
use App\Queries\Tax\TaxPeriodReturn;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use DateTimeImmutable;

/**
 * Tax periods (D15 item 13). Generate calendar months for an authority; file with a frozen snapshot of the return; reopen with a
 * second person and a reason. Filing never closes a ledger period and never blocks posting: whether a filed period changed is
 * read by comparing the return now with the snapshot.
 */
final class ManageTaxPeriods
{
    public const int MAX_MONTHS = 24;

    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
        private readonly SetupGuard $guard,
        private readonly TaxPeriodReturn $returns,
    ) {}

    /**
     * Creates the calendar months from the month of `$from`, `$months` of them; months that already exist exactly are skipped, so
     * running it twice changes nothing. A month overlapping a period of other dates is refused. `$dueDaysAfter` puts the due date that
     * many days after the period ends (a country pack's filing rule); null leaves it empty.
     *
     * @return list<TaxPeriod> the periods created
     */
    public function generate(Actor $actor, string $companyId, string $authority, DateTimeImmutable $from, int $months, ?int $dueDaysAfter = null): array
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $companyId, $authority, $from, $months, $dueDaysAfter) {
            $this->guard->require($actor, 'tax_periods.manage', $companyId);
            if ($months < 1 || $months > self::MAX_MONTHS) {
                throw DomainRuleViolation::single('tax_period_months_invalid', 'Generate between 1 and '.self::MAX_MONTHS.' months at a time.');
            }
            Company::query()->whereKey($companyId)->lock('FOR NO KEY UPDATE')->firstOrFail();

            $created = [];
            $start = $from->modify('first day of this month');
            for ($i = 0; $i < $months; $i++) {
                $first = $start->modify("+{$i} month");
                $last = $first->modify('last day of this month');
                $existing = TaxPeriod::query()->where('company_id', $companyId)->where('authority', $authority)
                    ->where('starts_on', '<=', $last->format('Y-m-d'))->where('ends_on', '>=', $first->format('Y-m-d'))->get();
                $same = $existing->first();
                if ($existing->count() === 1 && $same !== null && $same->starts_on->format('Y-m-d') === $first->format('Y-m-d') && $same->ends_on->format('Y-m-d') === $last->format('Y-m-d')) {
                    continue;
                }
                if ($existing->isNotEmpty()) {
                    throw DomainRuleViolation::single('tax_period_overlap', 'A tax period of other dates already covers '.$first->format('F Y').'.');
                }
                $period = new TaxPeriod;
                $period->forceFill([
                    'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $companyId, 'authority' => $authority,
                    'starts_on' => $first->format('Y-m-d'), 'ends_on' => $last->format('Y-m-d'),
                    'due_on' => $dueDaysAfter === null ? null : $last->modify("+{$dueDaysAfter} days")->format('Y-m-d'),
                    'status' => TaxPeriod::OPEN, 'manual_amounts' => [],
                ])->save();
                $audit->add('tax_period.created', $period, after: $period);
                $created[] = $period;
            }

            return $created;
        });
    }

    /**
     * Signed amounts for the sections a return cannot compute (adjustments, credit brought forward ...), while the period is open.
     *
     * @param  array<string, int>  $amounts  section key → signed minor units
     */
    public function saveManualAmounts(Actor $actor, string $periodId, int $version, array $amounts): TaxPeriod
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $periodId, $version, $amounts) {
            $period = $locks->document(TaxPeriod::query()->findOrFail($periodId), $version);
            $this->guard->require($actor, 'tax_periods.manage', $period->company_id);
            if ($period->status !== TaxPeriod::OPEN) {
                throw DomainRuleViolation::single('tax_period_filed', 'A filed period cannot be edited; reopen it first.');
            }
            $before = $period->attributesToArray();
            $merged = $period->manual_amounts;
            foreach ($amounts as $key => $amount) {
                $merged[(string) $key] = (int) $amount;
            }
            $period->forceFill(['manual_amounts' => $merged, 'lock_version' => $period->lock_version + 1])->save();
            $audit->add('tax_period.amounts_saved', $period, before: $before, after: $period);

            return $period;
        });
    }

    public function file(Actor $actor, string $periodId, int $version, string $reference): TaxPeriod
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $periodId, $version, $reference) {
            $period = $locks->document(TaxPeriod::query()->findOrFail($periodId), $version);
            $this->guard->require($actor, 'tax_periods.file', $period->company_id);
            if ($period->status !== TaxPeriod::OPEN) {
                throw DomainRuleViolation::single('tax_period_filed', 'This period is already filed.');
            }
            $reference = trim($reference);
            if ($reference === '') {
                throw DomainRuleViolation::single('tax_period_reference_required', "Enter the authority's reference for this filing.");
            }
            $now = $this->clock->now();
            if ($period->ends_on->format('Y-m-d') >= $now->format('Y-m-d')) {
                throw DomainRuleViolation::single('tax_period_not_ended', 'A period can be filed only after its last day.');
            }
            $company = Company::query()->findOrFail($period->company_id);
            $snapshot = $this->returns->current($company, $period);

            $before = $period->attributesToArray();
            $period->forceFill([
                'status' => TaxPeriod::FILED, 'filed_at' => $now->format('Y-m-d H:i:sP'), 'filed_by' => $actor->id,
                'authority_reference' => $reference, 'return_snapshot' => $snapshot, 'lock_version' => $period->lock_version + 1,
            ])->save();
            $audit->add('tax_period.filed', $period, before: $before, after: $period->only(['status', 'filed_at', 'filed_by', 'authority_reference']),
                context: ['net_tax_payable' => $snapshot['net_tax_payable']['amount'] ?? null]);

            return $period;
        });
    }

    public function reopen(Actor $actor, string $periodId, int $version, string $reason): TaxPeriod
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $periodId, $version, $reason) {
            $period = $locks->document(TaxPeriod::query()->findOrFail($periodId), $version);
            $this->guard->require($actor, 'tax_periods.reopen', $period->company_id);
            if ($period->status !== TaxPeriod::FILED) {
                throw DomainRuleViolation::single('tax_period_not_filed', 'Only a filed period can be reopened.');
            }
            if ($actor->id === null || $actor->id === $period->filed_by) {
                throw DomainRuleViolation::single('self_approval_forbidden', 'A filed period is reopened by someone other than the person who filed it.');
            }
            $reason = trim($reason);
            if (mb_strlen($reason) < 5) {
                throw DomainRuleViolation::single('tax_period_reason_required', 'Give the reason for reopening.');
            }
            $before = $period->attributesToArray();
            $now = $this->clock->now();
            $period->forceFill([
                'status' => TaxPeriod::OPEN, 'filed_at' => null, 'filed_by' => null, 'authority_reference' => null, 'return_snapshot' => null,
                'reopened_at' => $now->format('Y-m-d H:i:sP'), 'reopened_by' => $actor->id, 'reopen_reason' => $reason,
                'lock_version' => $period->lock_version + 1,
            ])->save();
            // The snapshot leaves the row, so the audit trail keeps it (in `before`).
            $audit->add('tax_period.reopened', $period, before: $before, after: $period->only(['status', 'reopened_at', 'reopened_by', 'reopen_reason']), context: ['reason' => $reason]);

            return $period;
        });
    }
}
