<?php

declare(strict_types=1);

namespace App\Actions\Admin\Staff;

use App\Domain\Admin\AdminRole;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;

/** Rules shared by the staff write Actions. Call inside an AdminTransaction. */
final class StaffGuards
{
    /**
     * The staff member, locked; 404 when unknown. The active owner rows are locked first, in id order, then the
     * target, so two owners demoting or deactivating each other at once serialise (no deadlock, and the second sees
     * the first's result) instead of both passing the last-owner check.
     */
    public static function lock(string $id): AdminUser
    {
        AdminUser::query()->where('role', AdminRole::Owner->value)->where('is_active', true)->orderBy('id')->lockForUpdate()->pluck('id');

        return AdminUser::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw DomainRuleViolation::single('not_found', 'Staff member not found.');
    }

    /** Refuses to remove the last active owner. Only valid after lock(), which holds the owner rows. */
    public static function assertNotLastOwner(AdminUser $target): void
    {
        $owners = AdminUser::query()->where('role', AdminRole::Owner->value)->where('is_active', true)->count();

        if ($target->role === AdminRole::Owner && $target->is_active && $owners <= 1) {
            throw DomainRuleViolation::single('last_owner', 'There must always be at least one active owner.');
        }
    }
}
