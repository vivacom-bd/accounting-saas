<?php

declare(strict_types=1);

namespace App\Policies\EInvoicing;

use App\Policies\PolicyContext;
use Illuminate\Contracts\Auth\Authenticatable;

/** E-invoicing (`einvoicing.view|manage`). People's work: API tokens carry no abilities here. */
final class EInvoicingPolicy
{
    public function __construct(private readonly PolicyContext $context) {}

    public function viewAny(Authenticatable $principal): bool
    {
        return $this->context->personCan($principal, 'einvoicing.view');
    }

    public function manage(Authenticatable $principal): bool
    {
        return $this->context->personCan($principal, 'einvoicing.manage');
    }
}
