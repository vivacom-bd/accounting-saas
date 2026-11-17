<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The starting catalogue (plan Task 9): starter, professional, business and enterprise with the limits and prices
 * of the mock admin screens, plus the four add-ons. Idempotent: a plan's first version is inserted once and never
 * rewritten (versions are immutable); add-ons are upserted by code. Loaded by the create_plans migration; run
 * again with `php artisan db:seed --class=PlanSeeder`.
 */
final class PlanSeeder extends Seeder
{
    private const string EFFECTIVE_FROM = '2026-01-01T00:00:00Z';

    public function run(): void
    {
        self::sync(DB::connection());
    }

    public static function sync(ConnectionInterface $db): void
    {
        $now = now();
        foreach (self::plans() as $sort => $plan) {
            $id = $db->table('plans')->whereRaw('lower(code) = ?', [$plan['code']])->value('id');
            if ($id === null) {
                $id = (string) Str::uuid7();
                $db->table('plans')->insert([
                    'id' => $id, 'code' => $plan['code'], 'name' => $plan['name'], 'description' => $plan['description'],
                    'is_active' => true, 'sort' => $sort + 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            if (! $db->table('plan_versions')->where('plan_id', $id)->exists()) {
                $db->table('plan_versions')->insert([
                    'id' => (string) Str::uuid7(), 'plan_id' => $id, 'version' => 1,
                    'prices' => json_encode($plan['prices'], JSON_THROW_ON_ERROR),
                    'limits' => json_encode($plan['limits'], JSON_THROW_ON_ERROR),
                    'entitlements' => json_encode($plan['entitlements'], JSON_THROW_ON_ERROR),
                    'trial_days' => $plan['trial_days'], 'effective_from' => self::EFFECTIVE_FROM, 'created_at' => $now,
                ]);
            }
        }

        foreach (self::addons() as $addon) {
            $row = [
                'name' => $addon['name'], 'kind' => $addon['kind'], 'price' => json_encode($addon['price'], JSON_THROW_ON_ERROR),
                'unit_label' => $addon['unit_label'], 'updated_at' => $now,
            ];
            if ($db->table('plan_addons')->whereRaw('lower(code) = ?', [$addon['code']])->exists()) {
                continue; // edited in the admin since; the seed never overwrites
            }
            $db->table('plan_addons')->insert($row + ['id' => (string) Str::uuid7(), 'code' => $addon['code'], 'is_active' => true, 'created_at' => $now]);
        }
    }

    /**
     * Enterprise is a custom quote: null prices, the mock's limits as the starting point for a negotiated deal.
     * `api_clients` is not in the mock; the values here are a proposal for the owner to confirm.
     *
     * @return list<array{code: string, name: string, description: string, trial_days: int, prices: array<string, array<string, int|null>>, limits: array<string, int|null>, entitlements: array<string, bool>}>
     */
    private static function plans(): array
    {
        $flags = static fn (bool ...$on): array => array_combine(
            ['multi_currency', 'independent_approvals', 'fixed_assets', 'budgets', 'api_access', 'consolidation', 'audit_chain_export', 'custom_roles'],
            $on,
        );
        $quote = ['USD' => ['monthly' => null, 'annual' => null], 'KES' => ['monthly' => null, 'annual' => null], 'EUR' => ['monthly' => null, 'annual' => null]];

        return [
            [
                'code' => 'starter', 'name' => 'Starter', 'trial_days' => 14,
                'description' => 'Essential double-entry bookkeeping for small businesses and consultancies.',
                'prices' => ['USD' => ['monthly' => 4900, 'annual' => 47000], 'KES' => ['monthly' => 640000, 'annual' => 6140000], 'EUR' => ['monthly' => 4500, 'annual' => 43200]],
                'limits' => ['companies' => 1, 'users' => 3, 'api_clients' => 1, 'journal_lines_per_month' => 1000, 'storage_mb' => 2048, 'api_calls_per_day' => 500],
                'entitlements' => $flags(false, false, false, false, true, false, true, false),
            ],
            [
                'code' => 'professional', 'name' => 'Professional', 'trial_days' => 14,
                'description' => 'Controls-grade accounting with maker-checker approvals and bank reconciliation.',
                'prices' => ['USD' => ['monthly' => 14900, 'annual' => 143000], 'KES' => ['monthly' => 1950000, 'annual' => 18720000], 'EUR' => ['monthly' => 13900, 'annual' => 133400]],
                'limits' => ['companies' => 3, 'users' => 8, 'api_clients' => 3, 'journal_lines_per_month' => 5000, 'storage_mb' => 10240, 'api_calls_per_day' => 5000],
                'entitlements' => $flags(true, true, true, true, true, false, true, false),
            ],
            [
                'code' => 'business', 'name' => 'Business', 'trial_days' => 14,
                'description' => 'Full multi-entity consolidation, custom approvals, and compliance controls.',
                'prices' => ['USD' => ['monthly' => 34900, 'annual' => 335000], 'KES' => ['monthly' => 4550000, 'annual' => 43680000], 'EUR' => ['monthly' => 32500, 'annual' => 312000]],
                'limits' => ['companies' => 10, 'users' => 25, 'api_clients' => 10, 'journal_lines_per_month' => 25000, 'storage_mb' => 51200, 'api_calls_per_day' => 25000],
                'entitlements' => $flags(true, true, true, true, true, true, true, true),
            ],
            [
                'code' => 'enterprise', 'name' => 'Enterprise', 'trial_days' => 30,
                'description' => 'Bespoke volume limits, custom SLA, dedicated VPC, and compliance audit support.',
                'prices' => $quote,
                'limits' => ['companies' => 50, 'users' => 100, 'api_clients' => 50, 'journal_lines_per_month' => 150000, 'storage_mb' => 256000, 'api_calls_per_day' => 100000],
                'entitlements' => $flags(true, true, true, true, true, true, true, true),
            ],
        ];
    }

    /** @return list<array{code: string, name: string, kind: string, price: array<string, int>, unit_label: string}> */
    private static function addons(): array
    {
        return [
            ['code' => 'extra_company', 'name' => 'Additional Legal Entity / Company', 'kind' => 'company', 'price' => ['USD' => 2500, 'KES' => 325000, 'EUR' => 2300], 'unit_label' => 'company / month'],
            ['code' => 'user_pack_5', 'name' => '5 Additional User Seats Pack', 'kind' => 'user_pack', 'price' => ['USD' => 3500, 'KES' => 455000, 'EUR' => 3200], 'unit_label' => 'pack of 5 seats / month'],
            ['code' => 'storage_50gb', 'name' => '50 GB Document Archival Storage', 'kind' => 'storage', 'price' => ['USD' => 1500, 'KES' => 195000, 'EUR' => 1400], 'unit_label' => '50 GB / month'],
            ['code' => 'metered_api', 'name' => 'Metered High-Speed API Overages', 'kind' => 'metered_api', 'price' => ['USD' => 1000, 'KES' => 130000, 'EUR' => 900], 'unit_label' => 'per 10,000 requests'],
        ];
    }
}
