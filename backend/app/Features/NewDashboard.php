<?php

declare(strict_types=1);

namespace App\Features;

/** Example flag: the redesigned dashboard. */
final class NewDashboard extends Feature
{
    public string $name = 'new-dashboard';

    public function description(): string
    {
        return 'The redesigned dashboard.';
    }

    public function default(): bool
    {
        return false;
    }
}
