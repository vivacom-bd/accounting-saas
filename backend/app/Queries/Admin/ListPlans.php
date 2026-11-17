<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Contracts\Clock;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanVersion;
use Illuminate\Support\Facades\DB;

/** `GET /api/admin/plans`: every plan with its current and scheduled versions and how many tenants sit on it. */
final class ListPlans
{
    public function __construct(private readonly Clock $clock) {}

    /** @return list<array<string, mixed>> */
    public function __invoke(): array
    {
        $now = $this->clock->now();
        $plans = Plan::query()->orderBy('sort')->orderBy('code')->get();
        $versions = PlanVersion::query()->orderBy('version')->get()->groupBy('plan_id');
        $counts = $this->tenantCounts();

        $out = [];
        foreach ($plans as $plan) {
            $mine = $versions->get($plan->id, collect());
            $current = $mine->filter(fn (PlanVersion $v): bool => $v->effective_from <= $now)->last();
            $scheduled = $mine->filter(fn (PlanVersion $v): bool => $v->effective_from > $now)->values();

            $out[] = self::plan($plan) + [
                'current_version' => $current instanceof PlanVersion ? self::version($current) : null,
                'scheduled_versions' => $scheduled->map(fn (PlanVersion $v): array => self::version($v))->all(),
                'tenant_count' => $mine->sum(fn (PlanVersion $v): int => $counts[$v->id] ?? 0),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function plan(Plan $plan): array
    {
        return [
            'id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name, 'description' => $plan->description,
            'is_active' => $plan->is_active, 'sort' => $plan->sort,
        ];
    }

    /** @return array<string, mixed> */
    public static function version(PlanVersion $v): array
    {
        return [
            'id' => $v->id, 'plan_id' => $v->plan_id, 'version' => $v->version, 'prices' => $v->prices, 'limits' => $v->limits,
            'entitlements' => $v->entitlements, 'trial_days' => $v->trial_days, 'stripe_product_id' => $v->stripe_product_id,
            'effective_from' => $v->effective_from->format(DATE_ATOM),
        ];
    }

    /**
     * Tenants per plan version, from the version id provisioning stores on the tenant until subscriptions exist (Task 11).
     *
     * @return array<string, int>
     */
    private function tenantCounts(): array
    {
        $rows = DB::connection('pgsql_platform')->select(
            "SELECT settings->>'plan_version_id' AS version_id, count(*) AS n FROM tenants WHERE settings->>'plan_version_id' IS NOT NULL GROUP BY 1",
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->version_id] = (int) $row->n;
        }

        return $counts;
    }
}
