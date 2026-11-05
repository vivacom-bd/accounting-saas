<?php

declare(strict_types=1);

namespace App\Models\Tax;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * The country pack a company applied and its settings (plan 3c.22).
 *
 * @property string $id
 * @property string $company_id
 * @property string $pack_key
 * @property int $pack_version
 * @property string $authority
 * @property int|null $due_days_after_period
 * @property string $reverse_charge_mode gross | net_non_creditable
 * @property string|null $applied_by
 * @property Carbon $applied_at
 * @property Carbon $last_applied_at
 * @property int $lock_version
 */
class CompanyTaxPack extends BaseModel
{
    public const string GROSS = 'gross';

    public const string NET_NON_CREDITABLE = 'net_non_creditable';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['pack_version' => 'integer', 'due_days_after_period' => 'integer', 'applied_at' => 'datetime', 'last_applied_at' => 'datetime', 'lock_version' => 'integer'];
    }
}
