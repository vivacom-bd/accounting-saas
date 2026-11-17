<?php

declare(strict_types=1);

/*
 * Feature flags (plan Task 17). Scope is the organisation (Tenant model). The `ledgerly` driver reads on `connection`
 * (the tenant app role, SELECT only) and writes on `write_connection` (ledgerly_platform, admin edits only); see
 * App\Support\Features\LedgerlyFeatureDriver.
 */
return [
    'default' => env('PENNANT_STORE', 'database'),

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver' => 'ledgerly',
            'connection' => 'pgsql',
            'write_connection' => 'pgsql_platform',
            'table' => 'features',
        ],
    ],
];
