<?php

declare(strict_types=1);

namespace App\Features;

/** Every flag the platform defines, by name. Add a class here to make it appear in Pennant and on the admin flags page. */
final class FeatureCatalog
{
    /** @return array<string, class-string<Feature>> */
    public static function all(): array
    {
        $classes = [BetaReports::class, NewDashboard::class];

        $byName = [];
        foreach ($classes as $class) {
            $byName[(new $class)->name] = $class;
        }

        return $byName;
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::all());
    }
}
