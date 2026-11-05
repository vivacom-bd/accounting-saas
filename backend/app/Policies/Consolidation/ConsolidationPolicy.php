<?php

declare(strict_types=1);

namespace App\Policies\Consolidation;

use App\Domain\Organisation\ConsolidationPermissions;
use App\Models\Consolidation\ConsolidationGroup;
use App\Models\Consolidation\ConsolidationMember;
use App\Models\Platform\User;
use App\Policies\PolicyContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Consolidation (D16.10): a person with the organisation-level right AND `reports.view` in every member company of the
 * group. API clients never pass (the type check denies them). Registered for the group, member and run models; the
 * Actions check again.
 */
final class ConsolidationPolicy
{
    public function __construct(private readonly PolicyContext $context) {}

    public function viewAny(Authenticatable $principal): bool
    {
        return $principal instanceof User && ConsolidationPermissions::holds($this->context->orgRole($principal), ConsolidationPermissions::VIEW);
    }

    public function create(Authenticatable $principal): bool
    {
        return $principal instanceof User && ConsolidationPermissions::holds($this->context->orgRole($principal), ConsolidationPermissions::MANAGE);
    }

    public function view(Authenticatable $principal, Model $subject): bool
    {
        return $this->in($principal, $subject, ConsolidationPermissions::VIEW);
    }

    public function manage(Authenticatable $principal, Model $subject): bool
    {
        return $this->in($principal, $subject, ConsolidationPermissions::MANAGE);
    }

    public function run(Authenticatable $principal, Model $subject): bool
    {
        return $this->in($principal, $subject, ConsolidationPermissions::RUN);
    }

    /** Approving a run or a group entry: also not the person who made it (the Action enforces that too). */
    public function approve(Authenticatable $principal, Model $subject): bool
    {
        if (! $this->in($principal, $subject, ConsolidationPermissions::APPROVE)) {
            return false;
        }

        return ! in_array($principal->getAuthIdentifier(), [$subject->getAttribute('created_by'), $subject->getAttribute('submitted_by')], true);
    }

    private function in(Authenticatable $principal, Model $subject, string $permission): bool
    {
        if (! $principal instanceof User || ! ConsolidationPermissions::holds($this->context->orgRole($principal), $permission)) {
            return false;
        }
        $groupId = match (true) {
            $subject instanceof ConsolidationGroup => $subject->id,
            $subject instanceof ConsolidationMember => $subject->group_id,
            default => (string) $subject->getAttribute('group_id'),
        };
        foreach (ConsolidationMember::query()->where('group_id', $groupId)->pluck('company_id') as $companyId) {
            if (! $this->context->allows($principal, ConsolidationPermissions::MEMBER_VIEW, (string) $companyId)) {
                return false;
            }
        }

        return true;
    }
}
