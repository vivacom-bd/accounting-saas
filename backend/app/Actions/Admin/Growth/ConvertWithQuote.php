<?php

declare(strict_types=1);

namespace App\Actions\Admin\Growth;

use App\Actions\Billing\ChangePlan;
use App\Actions\Billing\ConvertTrial;
use App\Actions\Billing\Subscribe;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Subscription;
use App\Services\Billing\SubscriptionSupport;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Closes a trial by hand with a negotiated amount (plan Task 16): puts the organisation on the chosen plan with a custom
 * monthly/annual amount, makes it active (an MRR event is recorded) and audits the quote. Reuses the billing Actions so
 * the same gateway and lifecycle rules apply as everywhere else.
 */
final class ConvertWithQuote
{
    public function __construct(
        private readonly Subscribe $subscribe,
        private readonly ChangePlan $changePlan,
        private readonly ConvertTrial $convertTrial,
        private readonly SubscriptionSupport $support,
        private readonly AdminAuditWriter $audit,
    ) {}

    public function handle(
        AdminActor $actor,
        string $tenantId,
        string $planCode,
        string $interval,
        string $currency,
        int $customAmount,
        string $collection = 'offline',
        ?string $note = null,
    ): Subscription {
        if ($customAmount < 0) {
            throw DomainRuleViolation::single('subscription_invalid', 'A custom amount cannot be negative.');
        }

        $existing = $this->support->liveOrNull($tenantId, false);
        if ($existing !== null) {
            $this->changePlan($actor, $tenantId, $planCode, $interval, $customAmount, $note);
        } else {
            $this->subscribe($actor, $tenantId, $planCode, $interval, $currency, $collection, $customAmount, $note);
        }

        $tenant = AdminTransaction::connection()->table('tenants')->where('id', $tenantId)->first(['lifecycle']);
        if ($tenant !== null && $tenant->lifecycle === 'trial') {
            $this->convertTrial($actor, $tenantId, $note);
        }

        $this->audit->record($actor, 'growth.quoted_conversion', $tenantId, 'tenant', $tenantId, null, [
            'plan_code' => $planCode, 'interval' => $interval, 'currency' => $currency, 'custom_amount' => $customAmount, 'collection' => $collection,
        ], $note);

        return $this->support->liveOrNull($tenantId, false)
            ?? throw DomainRuleViolation::single('subscription_not_found', 'The subscription could not be created.');
    }

    private function subscribe(AdminActor $actor, string $tenantId, string $planCode, string $interval, string $currency, string $collection, int $customAmount, ?string $note): void
    {
        ($this->subscribe)($actor, $tenantId, $planCode, $interval, $currency, $collection, $customAmount, null, $note ?? 'Quoted conversion from growth');
    }

    private function changePlan(AdminActor $actor, string $tenantId, string $planCode, string $interval, int $customAmount, ?string $note): void
    {
        ($this->changePlan)($actor, $tenantId, $planCode, $interval, $customAmount, true, $note ?? 'Quoted conversion from growth');
    }

    private function convertTrial(AdminActor $actor, string $tenantId, ?string $note): void
    {
        ($this->convertTrial)($actor, $tenantId, $note ?? 'Quoted conversion from growth');
    }
}
