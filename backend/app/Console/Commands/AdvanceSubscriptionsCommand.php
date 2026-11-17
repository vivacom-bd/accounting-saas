<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Billing\AdvanceSubscriptions;
use Illuminate\Console\Command;

/** Daily step for offline subscriptions (plan Task 11): due cancellations, ended pauses, renewals. Idempotent. */
final class AdvanceSubscriptionsCommand extends Command
{
    protected $description = 'Apply due cancellations, ended pauses and renewals to offline (invoiced) subscriptions';

    protected $signature = 'subscriptions:advance';

    public function handle(AdvanceSubscriptions $advance): int
    {
        $done = $advance();
        $this->info("Subscriptions cancelled: {$done['cancelled']}, resumed from pause: {$done['woken']}, renewed: {$done['rolled']}");

        return self::SUCCESS;
    }
}
