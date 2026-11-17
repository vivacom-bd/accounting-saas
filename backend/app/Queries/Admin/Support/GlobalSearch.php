<?php

declare(strict_types=1);

namespace App\Queries\Admin\Support;

use App\Support\Admin\AdminTransaction;

/**
 * `GET /api/admin/search?q=`: at most 10 of each kind. Tenants by name or slug (contains), users by email (exact or
 * prefix) with the tenants they belong to, companies through sysops.admin_search_companies, invoices by number or
 * Stripe id. LIKE wildcards in the query are literal.
 */
final class GlobalSearch
{
    private const int LIMIT = 10;

    /** @return array{tenants: list<array<string, mixed>>, users: list<array<string, mixed>>, companies: list<array<string, mixed>>, invoices: list<array<string, mixed>>} */
    public function __invoke(string $q): array
    {
        $db = AdminTransaction::connection();
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

        $tenants = $db->table('tenants')
            ->where(fn ($w) => $w->where('name', 'ilike', "%{$escaped}%")->orWhere('slug', 'ilike', "%{$escaped}%"))
            ->orderBy('name')->orderBy('id')->limit(self::LIMIT)
            ->get(['id', 'name', 'slug', 'lifecycle', 'country']);

        $users = $db->table('users')->whereRaw('lower(email) like ?', [mb_strtolower($escaped).'%'])
            ->orderBy('email')->orderBy('id')->limit(self::LIMIT)
            ->get(['id', 'name', 'email']);
        $memberships = $users->isEmpty() ? collect() : $db->table('tenant_users')->whereIn('user_id', $users->pluck('id'))
            ->orderBy('tenant_id')->get(['user_id', 'tenant_id'])->groupBy('user_id');

        $companies = $db->select('SELECT * FROM sysops.admin_search_companies(?, ?)', [$q, self::LIMIT]);

        $invoices = $db->table('stripe_invoices')
            ->where(fn ($w) => $w->where('number', 'ilike', "%{$escaped}%")->orWhere('stripe_id', 'ilike', "%{$escaped}%"))
            ->orderBy('number')->orderBy('id')->limit(self::LIMIT)
            ->get(['id', 'tenant_id', 'number', 'stripe_id', 'status', 'currency', 'total']);

        return [
            'tenants' => array_values($tenants->map(fn (object $t): array => [
                'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->lifecycle, 'country' => $t->country,
            ])->all()),
            'users' => array_values($users->map(fn (object $u): array => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'tenant_ids' => $memberships->get($u->id, collect())->pluck('tenant_id')->values()->all(),
            ])->all()),
            'companies' => array_values(array_map(fn (object $c): array => (array) $c, $companies)),
            'invoices' => array_values($invoices->map(fn (object $i): array => [
                'id' => $i->id, 'tenant_id' => $i->tenant_id, 'number' => $i->number, 'stripe_id' => $i->stripe_id,
                'status' => $i->status, 'currency' => $i->currency, 'total' => (int) $i->total,
            ])->all()),
        ];
    }
}
