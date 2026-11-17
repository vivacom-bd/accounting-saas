<?php

declare(strict_types=1);

return [
    /*
     * The plan a tenant is on when none was assigned (self-registered organisations, rows from before billing).
     * Resolved to the plan's current version by sysops.current_entitlements() (plan Task 10).
     */
    'default_plan' => env('BILLING_DEFAULT_PLAN', 'starter'),
];
