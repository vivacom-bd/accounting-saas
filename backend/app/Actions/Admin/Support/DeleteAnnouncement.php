<?php

declare(strict_types=1);

namespace App\Actions\Admin\Support;

use App\Domain\Shared\DomainRuleViolation;
use App\Queries\Admin\Support\Announcements;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/** Removes an announcement (it vanishes from every tenant at once). The audit event keeps what it said. */
final class DeleteAnnouncement
{
    public function __construct(
        private readonly AdminAuditWriter $audit,
        private readonly Announcements $announcements,
    ) {}

    public function handle(AdminActor $actor, string $id): void
    {
        $db = AdminTransaction::connection();

        AdminTransaction::run(function () use ($db, $actor, $id): void {
            $row = $this->announcements->find($id) ?? throw DomainRuleViolation::single('not_found', 'Announcement not found.');
            $db->table('announcements')->where('id', $id)->delete();
            $this->audit->record($actor, 'announcement.deleted', null, 'announcement', $id, Announcements::view($row));
        });
    }
}
