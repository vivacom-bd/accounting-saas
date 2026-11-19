<?php

declare(strict_types=1);

namespace App\Actions\Admin\Growth;

use App\Contracts\Clock;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeZone;

/**
 * Turns the automated trial nudges on or off (plan Task 16); the `nudges_enabled` row of platform_settings. Default is
 * on, so an absent row reads as enabled.
 */
final class SetNudgesEnabled
{
    public function __construct(private readonly Clock $clock, private readonly AdminAuditWriter $audit) {}

    /** @return array{enabled: bool} */
    public function handle(AdminActor $actor, bool $enabled): array
    {
        $db = AdminTransaction::connection();
        $after = ['enabled' => $enabled];

        AdminTransaction::run(function () use ($db, $actor, $after): void {
            $row = $db->table('platform_settings')->where('key', 'nudges_enabled')->lockForUpdate()->first();
            $before = $row === null ? null : json_decode((string) $row->value, true, flags: JSON_THROW_ON_ERROR);
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
            $value = json_encode($after, JSON_THROW_ON_ERROR);

            $row === null
                ? $db->table('platform_settings')->insert(['key' => 'nudges_enabled', 'value' => $value, 'updated_by' => $actor->id, 'updated_at' => $now])
                : $db->table('platform_settings')->where('key', 'nudges_enabled')->update(['value' => $value, 'updated_by' => $actor->id, 'updated_at' => $now]);

            $this->audit->record($actor, 'growth.nudges_set', null, 'platform_setting', 'nudges_enabled', is_array($before) ? $before : null, $after);
        });

        return $after;
    }
}
