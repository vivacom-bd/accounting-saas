<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Shared\Violation;

/** A hard plan limit (companies, users, API clients; G8) is reached: 402 `plan_limit_reached` with the limit in `meta`. */
final class PlanLimitReached extends DomainRuleViolation
{
    /** @var array{key: string, limit: int, current: int} */
    public readonly array $meta;

    public function __construct(string $key, int $limit, int $current)
    {
        $message = match ($key) {
            'companies' => "Your plan includes {$limit} ".($limit === 1 ? 'company' : 'companies').'. Upgrade the plan to add another.',
            'users' => "Your plan includes {$limit} ".($limit === 1 ? 'user' : 'users').' (people and open invitations). Upgrade the plan to add another.',
            'api_clients' => "Your plan includes {$limit} API ".($limit === 1 ? 'client' : 'clients').'. Upgrade the plan to add another.',
            default => "Your plan limit for {$key} is reached.",
        };
        parent::__construct('plan_limit_reached', $message, [new Violation('plan_limit_reached', $message)]);
        $this->meta = ['key' => $key, 'limit' => $limit, 'current' => $current];
    }
}
