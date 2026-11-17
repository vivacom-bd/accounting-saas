<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Billing\SyncBillingCatalogue;
use App\Contracts\BillingGateway;
use App\Domain\Shared\DomainRuleViolation;
use Illuminate\Console\Command;

/** Pushes plans, prices and coupons to Stripe and stores the ids back (plan Task 11). Refuses when Stripe is not configured. */
final class SyncStripeCatalogue extends Command
{
    protected $description = 'Create or look up the Stripe products, prices and coupons for the plan catalogue';

    protected $signature = 'stripe:sync-catalogue';

    public function handle(BillingGateway $gateway, SyncBillingCatalogue $sync): int
    {
        if (! $gateway->isConfigured()) {
            $this->error('Stripe is not configured (STRIPE_SECRET is empty). Nothing was synced.');

            return self::FAILURE;
        }

        try {
            $done = $sync();
        } catch (DomainRuleViolation $e) {
            $this->error("Sync stopped: {$e->getMessage()} Run it again to resume.");

            return self::FAILURE;
        }
        $this->info("Created or linked: {$done['products']} products, {$done['prices']} prices, {$done['coupons']} coupons");

        return self::SUCCESS;
    }
}
