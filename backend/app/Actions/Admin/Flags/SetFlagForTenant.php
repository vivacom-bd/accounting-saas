<?php

declare(strict_types=1);

namespace App\Actions\Admin\Flags;

use App\Domain\Shared\DomainRuleViolation;
use App\Features\FeatureCatalog;
use App\Models\Platform\Tenant;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use Laravel\Pennant\Feature;

/** Overrides a feature flag for one organisation; null removes the override so the global value applies again (plan Task 17). */
final class SetFlagForTenant
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function handle(AdminActor $actor, string $tenantId, string $name, ?bool $active): ?bool
    {
        if (! FeatureCatalog::has($name)) {
            throw DomainRuleViolation::single('not_found', 'Feature flag not found.');
        }
        $db = AdminTransaction::connection();

        AdminTransaction::run(function () use ($db, $actor, $tenantId, $name, $active): void {
            $tenant = Tenant::query()->find($tenantId) ?? throw DomainRuleViolation::single('not_found', 'Organisation not found.');
            $scope = Tenant::class.'|'.$tenant->getKey();
            $row = $db->table('features')->where('name', $name)->where('scope', $scope)->lockForUpdate()->first();
            $before = $row === null ? null : (bool) json_decode($row->value, true, flags: JSON_THROW_ON_ERROR);

            $scoped = Feature::for($tenant);
            match ($active) {
                true => $scoped->activate($name),
                false => $scoped->deactivate($name),
                null => $scoped->forget($name),
            };
            $this->audit->record($actor, 'flag.tenant_set', $tenantId, 'feature', $name, ['active' => $before], ['active' => $active]);
        });
        Feature::flushCache();

        return $active;
    }
}
