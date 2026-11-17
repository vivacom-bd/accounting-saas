<?php

declare(strict_types=1);

namespace App\Actions\Admin\Flags;

use App\Domain\Shared\DomainRuleViolation;
use App\Features\FeatureCatalog;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use App\Support\Features\LedgerlyFeatureDriver;
use Laravel\Pennant\Feature;

/** Switches a feature flag for every organisation without its own override; null removes the global switch (plan Task 17). */
final class SetFlagGlobally
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function handle(AdminActor $actor, string $name, ?bool $active): ?bool
    {
        if (! FeatureCatalog::has($name)) {
            throw DomainRuleViolation::single('not_found', 'Feature flag not found.');
        }
        $db = AdminTransaction::connection();

        AdminTransaction::run(function () use ($db, $actor, $name, $active): void {
            $row = $db->table('features')->where('name', $name)->where('scope', LedgerlyFeatureDriver::GLOBAL_SCOPE)->lockForUpdate()->first();
            $before = $row === null ? null : (bool) json_decode($row->value, true, flags: JSON_THROW_ON_ERROR);

            $scoped = Feature::for(LedgerlyFeatureDriver::GLOBAL_SCOPE);
            match ($active) {
                true => $scoped->activate($name),
                false => $scoped->deactivate($name),
                null => $scoped->forget($name),
            };
            $this->audit->record($actor, 'flag.global_set', null, 'feature', $name, ['active' => $before], ['active' => $active]);
        });
        Feature::flushCache();

        return $active;
    }
}
