<?php

declare(strict_types=1);

namespace App\Policies\Tax;

use App\Models\Tax\TaxPeriod;
use App\Policies\PolicyContext;
use Illuminate\Contracts\Auth\Authenticatable;

/** Tax periods (`tax_periods.view|manage|file|reopen`). People's work: API tokens carry no abilities here. */
final class TaxPeriodPolicy
{
    public function __construct(private readonly PolicyContext $context) {}

    public function viewAny(Authenticatable $principal): bool
    {
        return $this->context->personCan($principal, 'tax_periods.view');
    }

    public function view(Authenticatable $principal, TaxPeriod $period): bool
    {
        return $this->context->personCan($principal, 'tax_periods.view', $period->company_id);
    }

    public function create(Authenticatable $principal): bool
    {
        return $this->context->personCan($principal, 'tax_periods.manage');
    }

    public function update(Authenticatable $principal, TaxPeriod $period): bool
    {
        return $period->status === TaxPeriod::OPEN && $this->context->personCan($principal, 'tax_periods.manage', $period->company_id);
    }

    public function file(Authenticatable $principal, TaxPeriod $period): bool
    {
        return $period->status === TaxPeriod::OPEN && $this->context->personCan($principal, 'tax_periods.file', $period->company_id);
    }

    public function reopen(Authenticatable $principal, TaxPeriod $period): bool
    {
        return $period->status === TaxPeriod::FILED
            && $principal->getAuthIdentifier() !== $period->filed_by
            && $this->context->personCan($principal, 'tax_periods.reopen', $period->company_id);
    }
}
