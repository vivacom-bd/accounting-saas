<?php

declare(strict_types=1);

namespace App\Queries\Admin\Flags;

use App\Features\FeatureCatalog;
use App\Support\Admin\AdminTransaction;
use App\Support\Features\LedgerlyFeatureDriver;

/** Every defined feature flag with its default, global switch and how many organisations override it (plan Task 17). */
final class ListFlags
{
    /** @return list<array{name: string, description: string, default: bool, global: bool|null, overrides_count: int}> */
    public function __invoke(): array
    {
        $rows = AdminTransaction::connection()->table('features')->get(['name', 'scope', 'value'])->groupBy('name');

        $flags = [];
        foreach (FeatureCatalog::all() as $name => $class) {
            $feature = new $class;
            $stored = $rows->get($name, collect());
            $global = $stored->firstWhere('scope', LedgerlyFeatureDriver::GLOBAL_SCOPE);

            $flags[] = [
                'name' => $name,
                'description' => $feature->description(),
                'default' => $feature->default(),
                'global' => $global === null ? null : (bool) json_decode($global->value, true, flags: JSON_THROW_ON_ERROR),
                'overrides_count' => $stored->where('scope', '!=', LedgerlyFeatureDriver::GLOBAL_SCOPE)->count(),
            ];
        }

        return $flags;
    }
}
