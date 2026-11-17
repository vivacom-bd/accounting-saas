<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Platform\LifecycleState;
use App\Domain\Platform\TenantLifecycle;
use App\Domain\Shared\DomainRuleViolation;
use App\Notifications\TenantLifecycleChanged;
use App\Support\Actor;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Throwable;

/**
 * Moves a tenant along its lifecycle (plan Task 5, G5–G7). One platform transaction for staff (admin audit row) and the
 * system (scheduler, billing webhooks: lifecycle event only). Cancelling schedules the deletion 90 days later — blocked
 * under legal hold; reactivating a cancelled tenant withdraws it, and is refused once a deletion was executed.
 */
final class ChangeTenantLifecycle
{
    public const int DELETION_AFTER_DAYS = 90;

    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly AdminAuditWriter $audit,
    ) {}

    /**
     * @param  DateTimeImmutable|null  $at  when the move took effect (the scheduler passes each step's due instant); now by default
     * @param  LifecycleState|null  $expectedFrom  refuse unless the tenant is still in this state (the scheduler's stale-read guard)
     */
    public function handle(
        AdminActor|Actor $by,
        string $tenantId,
        LifecycleState $to,
        string $reason,
        ?DateTimeImmutable $at = null,
        ?LifecycleState $expectedFrom = null,
    ): void {
        if ($by instanceof Actor && $by->tenantId !== $tenantId) {
            throw new LogicException('The actor belongs to another tenant.');
        }
        $at = ($at ?? $this->clock->now())->setTimezone(new DateTimeZone('UTC'));
        $db = DB::connection(AdminTransaction::CONNECTION);

        AdminTransaction::run(function () use ($db, $by, $tenantId, $to, $reason, $at, $expectedFrom) {
            /** @var object{id: string, name: string, lifecycle: string, legal_hold: bool}|null $tenant */
            $tenant = $db->selectOne('SELECT id, name, lifecycle, legal_hold FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
            if ($tenant === null) {
                throw DomainRuleViolation::single('not_found', 'Organisation not found.');
            }
            $from = LifecycleState::from($tenant->lifecycle);

            if ($expectedFrom !== null && $from !== $expectedFrom) {
                throw self::invalid("The organisation is {$from->value} now, not {$expectedFrom->value}.");
            }
            // Deletion (purge) is not built this round (G7): nobody may set `deleted`.
            if ($to === LifecycleState::Deleted || ! TenantLifecycle::canMove($from, $to)) {
                throw self::invalid("An organisation cannot move from {$from->value} to {$to->value}.");
            }
            if ($from === LifecycleState::Cancelled) {
                $this->withdrawDeletion($db, $tenantId);
            }

            $stamp = $at->format('Y-m-d H:i:s.uP');
            $changes = ['lifecycle' => $to->value, 'lifecycle_since' => $stamp];
            if ($to === LifecycleState::PastDue) {
                $changes['delinquent_since'] = $stamp;
            } elseif ($to === LifecycleState::Active) {
                $changes['delinquent_since'] = null;
            }
            $db->table('tenants')->where('id', $tenantId)->update($changes);

            $db->table('tenant_lifecycle_events')->insert([
                'id' => $this->ids->next(),
                'tenant_id' => $tenantId,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'reason' => $reason,
                'actor_type' => $by instanceof AdminActor ? 'admin' : $by->type->value,
                'actor_id' => $by->id,
                'occurred_at' => $stamp,
            ]);

            if ($to === LifecycleState::Cancelled) {
                $this->scheduleDeletion($db, $tenantId, $at, (bool) $tenant->legal_hold);
            }

            if ($by instanceof AdminActor) {
                $this->audit->record(
                    $by, 'tenant.lifecycle_changed', $tenantId, 'tenant', $tenantId,
                    ['lifecycle' => $from->value], ['lifecycle' => $to->value, 'lifecycle_since' => $stamp], $reason,
                );
            }

            $owners = array_map(fn (object $r): string => (string) ((array) $r)['email'], $db->select(
                "SELECT u.email FROM tenant_users tu JOIN users u ON u.id = tu.user_id WHERE tu.tenant_id = ? AND tu.role = 'owner' AND tu.status = 'active'",
                [$tenantId],
            ));
            $notice = new TenantLifecycleChanged($tenant->name, $from->value, $to->value);
            $db->afterCommit(function () use ($owners, $notice) {
                foreach ($owners as $email) {
                    try {
                        Notification::route('mail', $email)->notify($notice);
                    } catch (Throwable $e) {
                        report($e); // the move stands; a failed notice must not undo it or stop the scheduler
                    }
                }
            });
        });
    }

    private static function invalid(string $message): DomainRuleViolation
    {
        return DomainRuleViolation::single('lifecycle_transition_invalid', $message);
    }

    private function withdrawDeletion(Connection $db, string $tenantId): void
    {
        if ($db->table('tenant_deletions')->where('tenant_id', $tenantId)->where('status', 'executed')->exists()) {
            throw self::invalid('This organisation was deleted and cannot be reactivated.');
        }
        $db->table('tenant_deletions')->where('tenant_id', $tenantId)->whereIn('status', ['scheduled', 'blocked'])
            ->update(['status' => 'withdrawn', 'updated_at' => $this->clock->now()]);
    }

    private function scheduleDeletion(Connection $db, string $tenantId, DateTimeImmutable $cancelledAt, bool $legalHold): void
    {
        if ($db->table('tenant_deletions')->where('tenant_id', $tenantId)->whereIn('status', ['scheduled', 'blocked'])->exists()) {
            return;
        }
        $now = $this->clock->now();
        $db->table('tenant_deletions')->insert([
            'id' => $this->ids->next(),
            'tenant_id' => $tenantId,
            'scheduled_for' => $cancelledAt->modify('+'.self::DELETION_AFTER_DAYS.' days')->format('Y-m-d'),
            'status' => $legalHold ? 'blocked' : 'scheduled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
