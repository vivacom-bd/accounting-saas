<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Contracts\CompanyAccess;
use App\Contracts\PermissionChecker;
use App\Models\Platform\User;
use App\Support\Actor;
use Spatie\Permission\PermissionRegistrar;

/**
 * Whether a person may have a report made for them by schedule (plan 3c.23): the same rights as exporting it by hand, plus the
 * right to schedule. Checked when a schedule is saved and again on every run, because roles change after a schedule is made.
 */
final class ReportAccess
{
    public function __construct(private readonly PermissionChecker $permissions, private readonly CompanyAccess $access, private readonly PermissionRegistrar $registrar) {}

    /** @return string|null why not, in plain words; null when the person may */
    public function refusal(Actor $owner, string $report, string $companyId): ?string
    {
        // A worker lives for hours and caches the role-to-permission map for the process: what the owner holds is read afresh,
        // because the whole point of this check is that rights change after a schedule is made.
        $this->registrar->forgetCachedPermissions();
        $user = $owner->id === null ? null : User::query()->find($owner->id);
        if ($user === null || ! $this->access->allows($user, $owner->tenantId, $companyId)) {
            return 'You no longer have access to this company.';
        }
        foreach (['reports.view', 'reports.export', 'reports.schedule'] as $permission) {
            if (! $this->permissions->allows($owner, $permission, $companyId)) {
                return $permission === 'reports.schedule'
                    ? 'You can no longer schedule reports for this company.'
                    : 'You can no longer view and export reports for this company.';
            }
        }
        if ($report === 'budget-vs-actual' && ! $this->permissions->allows($owner, 'budgets.view', $companyId)) {
            return 'You can no longer see budgets in this company.';
        }

        return null;
    }
}
