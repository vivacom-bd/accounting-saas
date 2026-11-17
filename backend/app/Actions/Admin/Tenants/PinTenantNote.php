<?php

declare(strict_types=1);

namespace App\Actions\Admin\Tenants;

use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/** Pins or unpins a note of this tenant. Setting the pin it already has changes nothing and records nothing. */
final class PinTenantNote
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function handle(AdminActor $actor, string $tenantId, string $noteId, bool $pinned): void
    {
        $db = AdminTransaction::connection();

        AdminTransaction::run(function () use ($db, $actor, $tenantId, $noteId, $pinned): void {
            /** @var object{pinned: bool}|null $note */
            $note = $db->selectOne('SELECT pinned FROM tenant_notes WHERE id = ? AND tenant_id = ? FOR UPDATE', [$noteId, $tenantId]);
            if ($note === null) {
                throw DomainRuleViolation::single('not_found', 'Note not found.');
            }
            if ((bool) $note->pinned === $pinned) {
                return;
            }
            $db->table('tenant_notes')->where('id', $noteId)->update(['pinned' => $pinned]);
            $this->audit->record(
                $actor, $pinned ? 'tenant.note_pinned' : 'tenant.note_unpinned', $tenantId, 'tenant_note', $noteId,
                ['pinned' => ! $pinned], ['pinned' => $pinned],
            );
        });
    }
}
