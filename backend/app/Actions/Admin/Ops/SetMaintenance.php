<?php

declare(strict_types=1);

namespace App\Actions\Admin\Ops;

use App\Contracts\Clock;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use App\Support\Ops\MaintenanceState;
use DateTimeImmutable;
use DateTimeZone;

/** Turns platform maintenance mode on or off (plan T8, Task 17); the `maintenance` row of platform_settings. */
final class SetMaintenance
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AdminAuditWriter $audit,
        private readonly MaintenanceState $state,
    ) {}

    /** @return array{enabled: bool, message: string|null, ends_at: string|null} */
    public function handle(AdminActor $actor, bool $enabled, ?string $message, ?DateTimeImmutable $endsAt): array
    {
        $db = AdminTransaction::connection();
        $after = [
            'enabled' => $enabled,
            'message' => $message === '' ? null : $message,
            'ends_at' => $endsAt?->setTimezone(new DateTimeZone('UTC'))->format(\DATE_ATOM),
        ];

        AdminTransaction::run(function () use ($db, $actor, $after): void {
            $row = $db->table('platform_settings')->where('key', 'maintenance')->lockForUpdate()->first();
            $before = $row === null ? null : json_decode((string) $row->value, true, flags: JSON_THROW_ON_ERROR);
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
            $value = json_encode($after, JSON_THROW_ON_ERROR);

            $row === null
                ? $db->table('platform_settings')->insert(['key' => 'maintenance', 'value' => $value, 'updated_by' => $actor->id, 'updated_at' => $now])
                : $db->table('platform_settings')->where('key', 'maintenance')->update(['value' => $value, 'updated_by' => $actor->id, 'updated_at' => $now]);

            $this->audit->record($actor, 'ops.maintenance_set', null, 'platform_setting', 'maintenance', is_array($before) ? $before : null, $after);
        });
        $this->state->forget();

        return $after;
    }
}
