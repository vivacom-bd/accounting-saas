<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;

/** Asks for a credit note (plan Task 13): the same two-person approval as a refund, with `type = credit_note`. */
final class RequestCreditNote
{
    public function __construct(private readonly RequestRefund $refund) {}

    public function handle(AdminActor $actor, string $invoiceId, int $amount, string $reason): AdminApproval
    {
        return $this->refund->handle($actor, $invoiceId, $amount, $reason, 'credit_note');
    }
}
