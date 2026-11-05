<?php

declare(strict_types=1);

namespace App\Models\Tax;

use App\Models\BaseModel;

/**
 * A tax code feeding a return section on one side (plan 3c.22).
 *
 * @property string $id
 * @property string $company_id
 * @property string $section_id
 * @property string $tax_code_id
 * @property string $side sales | purchases | reverse_charge
 */
class TaxReturnSectionCode extends BaseModel {}
