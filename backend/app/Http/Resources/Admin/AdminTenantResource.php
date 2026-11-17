<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Platform\Tenant;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A tenant as the admin directory and tenant page see it (plan Task 7). The owner and plan come from the columns
 * TenantDirectory::query() adds (null when the tenant was loaded without them); counts from
 * sysops.admin_tenant_counts. `mrr` stays null until subscriptions exist (Task 11). `health_score` is not computed.
 */
final class AdminTenantResource
{
    /**
     * @param  array{companies: int, users: int, last_activity_at: string|null}|null  $counts
     * @return array<string, mixed>
     */
    public static function make(Tenant $t, ?array $counts = null): array
    {
        $ownerEmail = $t->getAttribute('owner_email');
        $planCode = $t->getAttribute('plan_code');

        return [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'status' => $t->lifecycle,
            'country' => $t->getAttribute('country'),
            'owner' => is_string($ownerEmail) ? ['name' => $t->getAttribute('owner_name'), 'email' => $ownerEmail] : null,
            'plan' => is_string($planCode) ? ['code' => $planCode, 'name' => $t->getAttribute('plan_name')] : null,
            'mrr' => null,
            'companies_count' => $counts['companies'] ?? 0,
            'users_count' => $counts['users'] ?? 0,
            'created_at' => self::time($t->getAttribute('created_at')),
            'last_activity_at' => self::time($counts['last_activity_at'] ?? null),
            'trial_ends_at' => self::time($t->getAttribute('trial_ends_at')),
        ];
    }

    private static function time(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc()->format(DATE_ATOM) : null;
    }
}
