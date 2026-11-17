<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Admin\AdminAuditEvent;

/** One admin audit event as the audit viewer and the tenant page show it (no canonical form or hashes). */
final class AdminAuditEventResource
{
    /** @return array<string, mixed> */
    public static function make(AdminAuditEvent $e): array
    {
        return [
            'id' => $e->id,
            'admin_id' => $e->admin_id,
            'action' => $e->action,
            'tenant_id' => $e->tenant_id,
            'subject_type' => $e->subject_type,
            'subject_id' => $e->subject_id,
            'before' => $e->before,
            'after' => $e->after,
            'reason' => $e->reason,
            'ip' => $e->ip,
            'user_agent' => $e->user_agent,
            'request_id' => $e->request_id,
            'occurred_at' => $e->occurred_at->format('c'),
        ];
    }
}
