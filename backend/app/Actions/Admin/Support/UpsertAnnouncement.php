<?php

declare(strict_types=1);

namespace App\Actions\Admin\Support;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Queries\Admin\Support\Announcements;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/**
 * Creates or replaces an announcement (plan Task 15). The audience is `{plans?, lifecycles?, countries?}`; an absent
 * or empty list means everyone. Tenants see it through sysops.active_announcements(); this table is staff-only.
 */
final class UpsertAnnouncement
{
    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly AdminAuditWriter $audit,
        private readonly Announcements $announcements,
    ) {}

    /**
     * @param  array{title: string, body: string, kind: string, audience: array<string, list<string>>, starts_at: DateTimeImmutable, ends_at: DateTimeImmutable|null}  $data
     * @return array<string, mixed>
     */
    public function handle(AdminActor $actor, ?string $id, array $data): array
    {
        $db = AdminTransaction::connection();
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        $audience = array_filter($data['audience'], fn (array $list): bool => $list !== []);

        return AdminTransaction::run(function () use ($db, $actor, $id, $data, $audience, $now): array {
            $before = $id === null ? null : ($this->announcements->find($id) ?? throw DomainRuleViolation::single('not_found', 'Announcement not found.'));
            $columns = [
                'title' => $data['title'], 'body' => $data['body'], 'kind' => $data['kind'],
                'audience' => json_encode($audience === [] ? new stdClass : $audience, JSON_THROW_ON_ERROR),
                'starts_at' => $data['starts_at']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
                'ends_at' => $data['ends_at']?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
                'updated_at' => $now,
            ];
            if ($before === null) {
                $id = $this->ids->next();
                $db->table('announcements')->insert($columns + ['id' => $id, 'created_by' => $actor->id, 'created_at' => $now]);
            } else {
                $db->table('announcements')->where('id', $id)->update($columns);
            }

            $after = Announcements::view($this->announcements->find((string) $id) ?? throw new \LogicException('Announcement vanished.'));
            $this->audit->record($actor, $before === null ? 'announcement.created' : 'announcement.updated', null, 'announcement', (string) $id,
                $before === null ? null : Announcements::view($before), $after);

            return $after;
        });
    }
}
