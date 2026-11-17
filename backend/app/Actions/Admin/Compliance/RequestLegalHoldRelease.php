<?php

declare(strict_types=1);

namespace App\Actions\Admin\Compliance;

use App\Actions\Admin\Approvals\RequestApproval;
use App\Domain\Admin\ApprovalKind;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminTransaction;

/** Asks to lift a legal hold; the hold stays until a second owner approves (plan Task 18). */
final class RequestLegalHoldRelease
{
    public function __construct(private readonly RequestApproval $request) {}

    public function handle(AdminActor $actor, string $tenantId, string $reason): AdminApproval
    {
        $hold = AdminTransaction::connection()->table('tenants')->where('id', $tenantId)->value('legal_hold');
        if ($hold === null) {
            throw DomainRuleViolation::single('not_found', 'Organisation not found.');
        }
        if (! $hold) {
            throw DomainRuleViolation::single('legal_hold_not_set', 'This organisation is not under legal hold.');
        }

        return $this->request->handle($actor, ApprovalKind::LegalHoldRelease, $tenantId, ['tenant_id' => $tenantId], $reason);
    }
}
