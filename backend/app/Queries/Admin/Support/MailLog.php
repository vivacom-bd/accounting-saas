<?php

declare(strict_types=1);

namespace App\Queries\Admin\Support;

use App\Http\Support\CursorPage;
use App\Models\Admin\MailLogEntry;
use Illuminate\Http\Request;

/** `GET /api/admin/mail-log`: newest first, optionally one tenant's. Selects the masked recipient, never the hash. */
final class MailLog
{
    public function __invoke(Request $request, ?string $tenantId): CursorPage
    {
        $query = MailLogEntry::query()->select(['id', 'tenant_id', 'to_masked', 'subject', 'notification', 'status', 'sent_at']);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return CursorPage::fetch($query, $request, 'sent_at', true, 'timestamp');
    }
}
