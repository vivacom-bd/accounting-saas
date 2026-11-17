<?php

declare(strict_types=1);

namespace App\Actions\Admin\Tenants;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeZone;

/** A staff note on a tenant (plan Task 7). Notes are never edited or deleted; only their pin changes. */
final class AddTenantNote
{
    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly AdminAuditWriter $audit,
    ) {}

    /** Returns the new note's id. */
    public function handle(AdminActor $actor, string $tenantId, string $body): string
    {
        $db = AdminTransaction::connection();

        return AdminTransaction::run(function () use ($db, $actor, $tenantId, $body): string {
            if (! $db->table('tenants')->where('id', $tenantId)->exists()) {
                throw DomainRuleViolation::single('not_found', 'Organisation not found.');
            }
            $id = $this->ids->next();
            $db->table('tenant_notes')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'admin_id' => $actor->id, 'body' => $body, 'pinned' => false,
                'created_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            ]);
            $this->audit->record($actor, 'tenant.note_added', $tenantId, 'tenant_note', $id, null, ['body' => $body]);

            return $id;
        });
    }
}
