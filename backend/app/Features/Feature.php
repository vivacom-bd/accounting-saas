<?php

declare(strict_types=1);

namespace App\Features;

use App\Models\Platform\Tenant;

/**
 * A platform feature flag (plan Task 17). Scope is the organisation. The value an organisation gets, in order: its own
 * override, the global switch an admin set, then `default()`. Implementations name the flag with `$name` (kebab-case)
 * and describe it for the admin flags page.
 */
abstract class Feature
{
    public string $name;

    abstract public function description(): string;

    abstract public function default(): bool;

    public function resolve(Tenant $tenant): bool
    {
        return $this->default();
    }
}
