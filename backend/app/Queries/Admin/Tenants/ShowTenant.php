<?php

declare(strict_types=1);

namespace App\Queries\Admin\Tenants;

use App\Domain\Shared\DomainRuleViolation;
use App\Http\Resources\Admin\AdminTenantResource;
use App\Models\Platform\Tenant;
use App\Support\Admin\AdminTransaction;

/**
 * `GET /api/admin/tenants/{id}`: the directory row plus limits usage (hard limits of the plan version against the
 * live counts; limit null when the tenant has no plan or the plan sets none), the lifecycle timeline and the notes.
 */
final class ShowTenant
{
    private const array LIMITED = ['companies', 'users'];

    public function __construct(
        private readonly TenantTimeline $timeline,
        private readonly TenantNotes $notes,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(string $tenantId): array
    {
        $tenant = TenantDirectory::find($tenantId) ?? throw self::notFound();
        $counts = TenantDirectory::counts([$tenantId])[$tenantId] ?? null;

        return AdminTenantResource::make($tenant, $counts) + [
            'limits_usage' => self::limitsUsage($tenant, $counts),
            'lifecycle_timeline' => ($this->timeline)($tenantId),
            'notes' => ($this->notes)($tenantId),
        ];
    }

    /**
     * The directory resource for one tenant; 404 when there is none.
     *
     * @return array<string, mixed>
     */
    public static function row(string $tenantId): array
    {
        $tenant = TenantDirectory::find($tenantId) ?? throw self::notFound();

        return AdminTenantResource::make($tenant, TenantDirectory::counts([$tenantId])[$tenantId] ?? null);
    }

    /** 404 unless the tenant exists. */
    public static function mustExist(string $tenantId): void
    {
        Tenant::on(AdminTransaction::CONNECTION)->whereKey($tenantId)->exists() || throw self::notFound();
    }

    public static function notFound(): DomainRuleViolation
    {
        return DomainRuleViolation::single('not_found', 'Organisation not found.');
    }

    /**
     * @param  array{companies: int, users: int, last_activity_at: string|null}|null  $counts
     * @return array<string, array{used: int, limit: int|null}>
     */
    private static function limitsUsage(Tenant $tenant, ?array $counts): array
    {
        $raw = $tenant->getAttribute('plan_limits');
        $limits = is_string($raw) ? json_decode($raw, true) : null;
        $counts ??= ['companies' => 0, 'users' => 0, 'last_activity_at' => null];

        $out = [];
        foreach (self::LIMITED as $metric) {
            $limit = is_array($limits) && is_int($limits[$metric] ?? null) ? $limits[$metric] : null;
            $out[$metric] = ['used' => $counts[$metric], 'limit' => $limit];
        }

        return $out;
    }
}
