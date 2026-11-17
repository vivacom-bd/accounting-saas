<?php

declare(strict_types=1);

namespace App\Actions\Organisation;

use App\Actions\Organisation\Inputs\CompanyRoleInput;
use App\Domain\Organisation\PermissionMatrix;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Organisation\Company;
use App\Models\Organisation\Role;
use App\Models\Platform\TenantUser;
use App\Models\Platform\User;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use Spatie\Permission\PermissionRegistrar;

/** Gives an active tenant member a company role. Only org owners and admins may do this. */
final class AssignCompanyRole
{
    public function __construct(private readonly UnitOfWork $uow, private readonly PermissionRegistrar $registrar) {}

    public function handle(Actor $actor, CompanyRoleInput $in, bool $bootstrap = false): void
    {
        $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $in, $bootstrap) {
            [$user, $role] = self::resolve($actor, $in, $bootstrap);

            $previous = $this->registrar->getPermissionsTeamId();
            $this->registrar->setPermissionsTeamId($in->companyId);
            try {
                if ($user->hasRole($role)) {
                    return;
                }
                $user->assignRole($role);
            } finally {
                $this->registrar->setPermissionsTeamId($previous);
            }

            $audit->add('company_role.assigned', $role, after: ['user_id' => $user->id, 'role' => $role->name]);
        });
    }

    /**
     * Shared guard for assign and revoke.
     *
     * Only an organisation owner may assign or revoke the company Owner role; `$bootstrap` is for CreateCompany, where the creator
     * becomes Owner of the company they just made.
     *
     * @return array{User, Role}
     */
    public static function resolve(Actor $actor, CompanyRoleInput $in, bool $bootstrap = false): array
    {
        $isAdmin = $actor->type === ActorType::User && $actor->id !== null
            && TenantUser::query()->where('tenant_id', $actor->tenantId)->where('user_id', $actor->id)
                ->where('status', 'active')->whereIn('role', ['owner', 'admin'])->exists();
        if (! $isAdmin) {
            throw DomainRuleViolation::single('forbidden', 'Only organisation owners and admins can manage company roles.');
        }

        Company::query()->findOrFail($in->companyId);

        $member = TenantUser::query()->where('tenant_id', $actor->tenantId)->where('user_id', $in->userId)->where('status', 'active')->exists();
        if (! $member) {
            throw DomainRuleViolation::single('user_not_in_tenant', 'The user is not an active member of this organisation.');
        }

        $role = Role::query()->where('company_id', $in->companyId)->where('name', $in->roleName)->where('guard_name', 'web')->first();
        if ($role === null) {
            throw DomainRuleViolation::single('role_not_found', "The company has no role named {$in->roleName}.");
        }

        if (! $bootstrap && $role->is_system && $role->name === PermissionMatrix::OWNER) {
            $isOrgOwner = TenantUser::query()->where('tenant_id', $actor->tenantId)->where('user_id', $actor->id)
                ->where('status', 'active')->where('role', 'owner')->exists();
            if (! $isOrgOwner) {
                throw DomainRuleViolation::single('forbidden', 'Only an organisation owner can assign or revoke the Owner role.');
            }
        }

        return [User::query()->findOrFail($in->userId), $role];
    }
}
