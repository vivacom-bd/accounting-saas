<?php

declare(strict_types=1);

namespace App\Providers;

use App\Features\FeatureCatalog;
use App\Support\Features\LedgerlyFeatureDriver;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

/** Feature flags (plan Task 17): the `ledgerly` Pennant driver and the flags the platform defines. */
final class FeatureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Feature::extend('ledgerly', fn ($app): LedgerlyFeatureDriver => new LedgerlyFeatureDriver(
            $app['db'], $app['events'], $app['config'], 'database', [],
        ));

        foreach (FeatureCatalog::all() as $class) {
            Feature::define($class);
        }
    }
}
