<?php

declare(strict_types=1);

namespace App\Actions\Admin\Tenants;

use DateTimeImmutable;

/** What provisioning answers with; `created` is false when a retry found the tenant already there. */
final readonly class ProvisionedTenant
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $status,
        public ?string $country,
        public ?DateTimeImmutable $trialEndsAt,
        public string $planCode,
        public string $planName,
        public string $ownerEmail,
        public DateTimeImmutable $ownerInvitationExpiresAt,
        public bool $created,
    ) {}
}
