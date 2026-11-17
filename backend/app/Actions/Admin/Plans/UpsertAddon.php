<?php

declare(strict_types=1);

namespace App\Actions\Admin\Plans;

use App\Contracts\IdGenerator;
use App\Domain\Billing\Prices;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\PlanAddon;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/** Creates or updates an add-on by code. Add-ons are not versioned; subscriptions snapshot what they need. */
final class UpsertAddon
{
    public const array KINDS = ['company', 'user_pack', 'storage', 'metered_api'];

    public function __construct(private readonly AdminAuditWriter $audit, private readonly IdGenerator $ids) {}

    /** @param  array<array-key, mixed>  $price */
    public function __invoke(AdminActor $actor, string $code, string $name, string $kind, array $price, ?string $unitLabel): PlanAddon
    {
        $code = mb_strtolower(trim($code));
        if (! in_array($kind, self::KINDS, true)) {
            throw DomainRuleViolation::single('addon_kind_invalid', 'Add-on kind must be one of: '.implode(', ', self::KINDS).'.');
        }
        $price = Prices::validateFlat($price);

        return AdminTransaction::run(function () use ($actor, $code, $name, $kind, $price, $unitLabel): PlanAddon {
            $addon = PlanAddon::query()->whereRaw('lower(code) = ?', [$code])->lockForUpdate()->first();
            $before = $addon === null ? null : ['name' => $addon->name, 'kind' => $addon->kind, 'price' => $addon->price, 'unit_label' => $addon->unit_label];
            $after = ['name' => $name, 'kind' => $kind, 'price' => $price, 'unit_label' => $unitLabel];

            $addon ??= new PlanAddon;
            if (! $addon->exists) {
                $addon->forceFill(['id' => $this->ids->next(), 'code' => $code, 'is_active' => true]);
            }
            $addon->forceFill($after)->save();

            $this->audit->record($actor, $before === null ? 'addon.created' : 'addon.updated', null, 'plan_addon', $addon->id, $before, $after + ['code' => $code]);

            return $addon;
        });
    }
}
