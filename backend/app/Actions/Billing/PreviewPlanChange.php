<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Services\Billing\SubscriptionSupport;

/**
 * What changing the plan would invoice now (10 §3.2 "change plan with preview"). Stripe collection asks the gateway
 * (proration included). Offline subscriptions are invoiced by hand, so the preview is simply the new plan's full price
 * for the interval, without proration. A read: nothing is written or audited.
 */
final class PreviewPlanChange
{
    public function __construct(private readonly SubscriptionSupport $support) {}

    /** @return array{currency: string, amount_due: int, lines: list<array{description: string, amount: int}>} */
    public function __invoke(string $tenantId, string $planCode, ?string $interval = null): array
    {
        $sub = $this->support->live($tenantId, false);
        $this->support->requireGatewayFor($sub->collection);
        [$plan, $version] = $this->support->resolvePlan($planCode);
        $newInterval = $interval ?? $sub->interval;
        $amount = $this->support->amount($version, $sub->currency, $newInterval, null, $sub->quantity);

        if ($sub->collection === 'stripe') {
            $priceId = $this->support->stripePriceId($version->id, $sub->currency, $newInterval);
            $preview = $this->support->gateway()->previewChange((string) $sub->stripe_subscription_id, $priceId);

            return ['currency' => $preview->currency, 'amount_due' => $preview->amountDue, 'lines' => $preview->lines];
        }

        return [
            'currency' => $sub->currency,
            'amount_due' => $amount,
            'lines' => [['description' => "{$plan->name} ({$newInterval}ly), invoiced offline, no proration", 'amount' => $amount]],
        ];
    }
}
