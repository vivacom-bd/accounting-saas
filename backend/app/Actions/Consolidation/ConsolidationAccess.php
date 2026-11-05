<?php

declare(strict_types=1);

namespace App\Actions\Consolidation;

use App\Contracts\PermissionChecker;
use App\Domain\Organisation\ConsolidationPermissions;
use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Shared\Violation;
use App\Models\Consolidation\ConsolidationMember;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\Tenancy\OrganisationRole;

/**
 * The defence-in-depth check every consolidation Action repeats (D16.10): a person (never a token) holding the
 * organisation-level consolidation permission, who can also view reports in every company of the group.
 */
final class ConsolidationAccess
{
    public function __construct(private readonly PermissionChecker $permissions) {}

    /** @param list<string>|null $companyIds the companies to check; null = every member of the group */
    public function require(Actor $actor, string $permission, ?string $groupId = null, ?array $companyIds = null): void
    {
        $role = $actor->type === ActorType::User && $actor->id !== null ? OrganisationRole::of($actor->tenantId, $actor->id) : null;
        if (! ConsolidationPermissions::holds($role, $permission)) {
            throw DomainRuleViolation::single('forbidden', "This needs the {$permission} permission.");
        }
        $companyIds ??= $groupId === null ? [] : ConsolidationMember::query()->where('group_id', $groupId)->pluck('company_id')->all();
        $refused = [];
        foreach ($companyIds as $companyId) {
            if (! $this->permissions->allows($actor, ConsolidationPermissions::MEMBER_VIEW, (string) $companyId)) {
                $refused[] = new Violation('forbidden', "No view rights in company {$companyId}.");
            }
        }
        if ($refused !== []) {
            throw new DomainRuleViolation('forbidden', 'Consolidation needs view rights in every member company.', $refused);
        }
    }
}
