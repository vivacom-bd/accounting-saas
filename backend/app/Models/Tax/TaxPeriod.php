<?php

declare(strict_types=1);

namespace App\Models\Tax;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * A tax period of one authority (D15 item 13): open, then filed with a frozen snapshot of the return.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $authority
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $due_on
 * @property string $status
 * @property Carbon|null $filed_at
 * @property string|null $filed_by
 * @property string|null $authority_reference
 * @property array<string, mixed>|null $return_snapshot
 * @property array<string, int> $manual_amounts section key → signed minor units
 * @property Carbon|null $reopened_at
 * @property string|null $reopened_by
 * @property string|null $reopen_reason
 * @property int $lock_version
 */
class TaxPeriod extends BaseModel
{
    public const string OPEN = 'open';

    public const string FILED = 'filed';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'ends_on' => 'date', 'due_on' => 'date', 'filed_at' => 'datetime', 'reopened_at' => 'datetime',
            'return_snapshot' => 'array', 'manual_amounts' => 'array', 'lock_version' => 'integer',
        ];
    }
}
