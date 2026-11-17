<?php

declare(strict_types=1);

/*
 * Platform admin surface (10_SaaS_Admin, plan decisions T1–T3). Staff sign in at /api/admin with their own guard,
 * session cookie and database role; nothing here is shared with the tenant /api/v1 surface.
 */
return [
    // Host-locks the /api/admin route group when set (T1). Empty in dev and on the IP demo.
    'domain' => env('ADMIN_DOMAIN') ?: null,

    // Comma-separated CIDRs or addresses every admin request must come from, before sign-in. Empty = no global limit.
    // Each staff member may also carry their own list (admin_users.ip_allowlist).
    'ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', ''))))),

    // T2: idle and absolute session lifetimes.
    'idle_minutes' => (int) env('ADMIN_IDLE_MINUTES', 30),
    'absolute_hours' => (int) env('ADMIN_ABSOLUTE_HOURS', 12),

    // Minutes between a correct password and the TOTP step before the half-finished sign-in is dropped.
    'pending_minutes' => 10,

    // Two-person approvals (Task 4).
    'approval_ttl_hours' => (int) env('ADMIN_APPROVAL_TTL_HOURS', 24),

    // The session handler behind the admin cookie; the same store as tenant sessions unless set.
    'session_driver' => env('ADMIN_SESSION_DRIVER', env('SESSION_DRIVER', 'database')),

    // Platform operations (plan Task 17): the queues whose depth the health page reports.
    'ops' => [
        'queues' => ['default', 'mail', 'maintenance', 'exports', 'scans', 'webhooks', 'imports'],
    ],
];
