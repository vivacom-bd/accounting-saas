<?php

declare(strict_types=1);

namespace App\Policies\Transactions;

use App\Domain\Workflow\DocumentStatus;
use App\Models\Platform\User;
use App\Models\Transactions\WriteOffRecovery;
use App\Policies\PolicyContext;
use Illuminate\Contracts\Auth\Authenticatable;

/** Recoveries after write-off share the `write_offs.*` permissions: tokens read, people create, approve (maker ≠ checker) and reverse. */
final class WriteOffRecoveryPolicy
{
    public function __construct(private readonly PolicyContext $context) {}

    public function viewAny(Authenticatable $principal): bool
    {
        return $this->context->can($principal, 'write_offs.view', 'write-offs:read');
    }

    public function create(Authenticatable $principal): bool
    {
        return $this->context->personCan($principal, 'write_offs.create');
    }

    public function view(Authenticatable $principal, WriteOffRecovery $r): bool
    {
        return $this->context->can($principal, 'write_offs.view', 'write-offs:read', $r->company_id);
    }

    public function update(Authenticatable $principal, WriteOffRecovery $r): bool
    {
        return $r->status === DocumentStatus::Draft && $this->context->personCan($principal, 'write_offs.create', $r->company_id);
    }

    public function submit(Authenticatable $principal, WriteOffRecovery $r): bool
    {
        return $this->update($principal, $r);
    }

    public function approve(Authenticatable $principal, WriteOffRecovery $r): bool
    {
        return $principal instanceof User
            && $r->status === DocumentStatus::PendingApproval
            && ! in_array($principal->id, [$r->created_by, $r->submitted_by], true)
            && $this->context->allows($principal, 'write_offs.approve', $r->company_id);
    }

    public function return(Authenticatable $principal, WriteOffRecovery $r): bool
    {
        return $this->approve($principal, $r);
    }

    public function void(Authenticatable $principal, WriteOffRecovery $r): bool
    {
        if (! $principal instanceof User || ! in_array($r->status, [DocumentStatus::Draft, DocumentStatus::PendingApproval], true)) {
            return false;
        }

        return ($r->created_by === $principal->id && $this->context->allows($principal, 'write_offs.create', $r->company_id))
            || $this->context->allows($principal, 'write_offs.approve', $r->company_id);
    }

    public function reverse(Authenticatable $principal, WriteOffRecovery $r): bool
    {
        return $principal instanceof User && $r->status === DocumentStatus::Posted && $this->context->allows($principal, 'write_offs.reverse', $r->company_id);
    }
}
