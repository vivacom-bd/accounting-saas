<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Platform\Tenant;
use Illuminate\Support\Str;

/** The URL-safe, unique handle of an organisation: its slugged name, with a short random suffix on a clash. */
final class TenantSlug
{
    public static function unique(string $name): string
    {
        $base = Str::limit(Str::slug($name), 48, '') ?: 'organisation';
        $slug = $base;
        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
