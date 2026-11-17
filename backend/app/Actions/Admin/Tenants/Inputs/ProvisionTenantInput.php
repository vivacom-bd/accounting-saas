<?php

declare(strict_types=1);

namespace App\Actions\Admin\Tenants\Inputs;

/**
 * `tenantId` is derived from the request's Idempotency-Key, so a retry finds the tenant it already made.
 * `currency` defaults to the first of USD, KES, EUR the plan version prices, `interval` is `month` or `year`, and a plan with a
 * custom quote needs `customAmount`. `billing` is `trial` (the plan's trial, or `trialDays`) or `offline` (active, invoiced by hand).
 */
final readonly class ProvisionTenantInput
{
    public function __construct(
        public string $tenantId,
        public string $organisationName,
        public string $country,
        public string $ownerEmail,
        public string $ownerName,
        public string $planCode,
        public string $billing,
        public ?int $trialDays = null,
        public ?string $note = null,
        public ?string $currency = null,
        public string $interval = 'month',
        public ?int $customAmount = null,
    ) {}
}
