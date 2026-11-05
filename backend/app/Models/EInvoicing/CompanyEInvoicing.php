<?php

declare(strict_types=1);

namespace App\Models\EInvoicing;

use App\Models\BaseModel;

/**
 * A company's e-invoicing settings (plan 3c.27). No row, or `enabled` false, means off.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property bool $enabled
 * @property string|null $adapter_key
 * @property string|null $seller_tax_id
 * @property string|null $seller_branch
 * @property string|null $changed_by
 * @property int $lock_version
 */
class CompanyEInvoicing extends BaseModel
{
    protected $table = 'company_einvoicing';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'lock_version' => 'integer'];
    }
}
