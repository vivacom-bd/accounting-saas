<?php

declare(strict_types=1);

namespace App\Actions\Consolidation\Inputs;

/**
 * A consolidation entry to record. Lines carry a signed `net` (debit positive, credit negative) in minor units of the
 * group's presentation currency. `member_id` says which member a line concerns, so a worksheet cell can drill to it.
 */
final readonly class EntryDraftInput
{
    /**
     * @param  list<array{account_id: string, member_id?: string|null, net: int, description?: string|null}>  $lines
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public string $groupId,
        public string $kind,
        public string $entryDate,
        public array $lines,
        public ?string $memo = null,
        public ?string $memberId = null,
        public ?string $runId = null,
        public array $detail = [],
    ) {}
}
