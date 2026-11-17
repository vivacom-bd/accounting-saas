<?php

declare(strict_types=1);

namespace App\Queries\Organisation;

/** The organisation's plan and its meters (plan Task 10). */
final readonly class BillingUsageReport
{
    /** @param list<array{key: string, limit: int|null, used: int, ratio: float|null, level: 'ok'|'warn'|'over'}> $meters */
    public function __construct(public ?string $planCode, public array $meters) {}
}
