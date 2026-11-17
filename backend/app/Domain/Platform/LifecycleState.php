<?php

declare(strict_types=1);

namespace App\Domain\Platform;

/** A tenant's commercial lifecycle (10_SaaS_Admin §5); values match the `tenants.lifecycle` enum. */
enum LifecycleState: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case ReadOnly = 'read_only';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Deleted = 'deleted';
}
