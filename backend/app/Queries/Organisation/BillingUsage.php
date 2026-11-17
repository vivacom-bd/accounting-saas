<?php

declare(strict_types=1);

namespace App\Queries\Organisation;

use App\Services\Billing\Entitlements;

/** The organisation's plan and its meters (plan Task 10). Runs under the request's tenant. */
final class BillingUsage
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function __invoke(): BillingUsageReport
    {
        return new BillingUsageReport($this->entitlements->snapshot()['plan_code'], $this->entitlements->meters());
    }
}
