<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Database\DatabaseManager;

/**
 * An in-app notification to every active organisation owner of the current tenant, for what only operators and owners should
 * hear about (a drift in the books, a broken audit chain). `$dedupeKey` makes it once per owner: sending the same key twice
 * stores nothing new.
 */
final class OwnerAlerts
{
    public function __construct(private readonly DatabaseManager $db, private readonly NotificationWriter $writer) {}

    /** @return list<string> owner ids newly notified */
    public function send(string $tenantId, string $companyId, string $kind, string $title, string $body, string $dedupeKey, ?string $link = null): array
    {
        $owners = array_map(
            fn (object $r): string => (string) ((array) $r)['user_id'],
            $this->db->connection()->select("SELECT user_id FROM tenant_users WHERE tenant_id = ? AND role = 'owner' AND status = 'active' ORDER BY user_id", [$tenantId]),
        );

        return $this->writer->store($tenantId, array_values(array_map(
            fn (string $owner): NotificationDraft => new NotificationDraft($owner, $companyId, $kind, $title, $body, null, null, $link, $dedupeKey),
            $owners,
        )));
    }
}
