<?php

declare(strict_types=1);

namespace App\Services\Billing\Dto;

use DateTimeImmutable;

/** A subscription as the gateway reports it, normalised to our status vocabulary. */
final readonly class GatewaySubscription
{
    /** @param  'trialing'|'active'|'past_due'|'paused'|'cancelled'  $status */
    public function __construct(
        public string $id,
        public string $status,
        public ?DateTimeImmutable $currentPeriodEnd,
        public ?DateTimeImmutable $trialEnd,
        public ?DateTimeImmutable $cancelAt,
    ) {}
}
