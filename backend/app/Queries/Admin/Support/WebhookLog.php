<?php

declare(strict_types=1);

namespace App\Queries\Admin\Support;

use App\Support\Admin\AdminTransaction;

/** A tenant's webhook deliveries through sysops.admin_webhook_deliveries: endpoint host only, no payloads or signatures. */
final class WebhookLog
{
    /** @return list<array<string, mixed>> */
    public function __invoke(string $tenantId, int $limit): array
    {
        $rows = AdminTransaction::connection()->select('SELECT * FROM sysops.admin_webhook_deliveries(?, ?)', [$tenantId, $limit]);

        return array_values(array_map(fn (object $row): array => (array) $row, $rows));
    }
}
