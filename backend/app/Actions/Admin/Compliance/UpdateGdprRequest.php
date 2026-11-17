<?php

declare(strict_types=1);

namespace App\Actions\Admin\Compliance;

use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/** Moves a data-subject request along open → in_progress → done|rejected (plan Task 18). A closed request stays closed. */
final class UpdateGdprRequest
{
    public function __construct(private readonly Clock $clock, private readonly AdminAuditWriter $audit) {}

    public function handle(AdminActor $actor, string $id, string $status, ?string $note): void
    {
        $db = AdminTransaction::connection();

        AdminTransaction::run(function () use ($db, $actor, $id, $status, $note): void {
            $row = $db->selectOne('SELECT tenant_id, status, notes FROM gdpr_requests WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null) {
                throw DomainRuleViolation::single('not_found', 'Request not found.');
            }
            $r = (array) $row;
            if (in_array($r['status'], ['done', 'rejected'], true)) {
                throw DomainRuleViolation::single('gdpr_request_closed', 'This request is closed and cannot change.');
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
            $closing = in_array($status, ['done', 'rejected'], true);
            $notes = $note ?? $r['notes'];
            $db->table('gdpr_requests')->where('id', $id)->update([
                'status' => $status, 'notes' => $notes, 'completed_at' => $closing ? $now : null, 'updated_at' => $now,
            ]);
            $this->audit->record($actor, 'gdpr.updated', (string) $r['tenant_id'], 'gdpr_request', $id,
                ['status' => $r['status']], ['status' => $status, 'note' => $note], $note);
        });
    }
}
