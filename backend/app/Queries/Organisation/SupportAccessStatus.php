<?php

declare(strict_types=1);

namespace App\Queries\Organisation;

use App\Contracts\Clock;
use App\Models\Organisation\SupportAccessGrant;

/** The organisation's open support-access grant (plan Task 14), if any: not revoked and not expired. */
final class SupportAccessStatus
{
    public function __construct(private readonly Clock $clock) {}

    public function open(): ?SupportAccessGrant
    {
        return SupportAccessGrant::query()->whereNull('revoked_at')->where('expires_at', '>', $this->clock->now())
            ->orderByDesc('expires_at')->orderBy('id')->first();
    }
}
