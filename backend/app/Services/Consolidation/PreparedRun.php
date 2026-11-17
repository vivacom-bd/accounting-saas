<?php

declare(strict_types=1);

namespace App\Services\Consolidation;

use App\Domain\Consolidation\RunInput;

/**
 * The input of a run with what must be stored beside it: per member the company, window, rates and ledger version read
 * when the figures were read, and the warnings that do not stop the run.
 *
 * @phpstan-type MemberMeta array{company_id: string, currency: string, window_from: string, ledger_version: int, closing_rate: string|null, average_rate: string|null, rate_detail: array<string, mixed>, opening_id: string|null}
 */
final readonly class PreparedRun
{
    /**
     * @param  array<string, MemberMeta>  $members  by member id
     * @param  list<array<string, mixed>>  $warnings
     */
    public function __construct(public RunInput $input, public array $members, public array $warnings) {}
}
