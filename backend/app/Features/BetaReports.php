<?php

declare(strict_types=1);

namespace App\Features;

/** Example flag: early access to reports still being tuned. */
final class BetaReports extends Feature
{
    public string $name = 'beta-reports';

    public function description(): string
    {
        return 'Early access to reports that are still being tuned.';
    }

    public function default(): bool
    {
        return false;
    }
}
