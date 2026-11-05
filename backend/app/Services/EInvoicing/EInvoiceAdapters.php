<?php

declare(strict_types=1);

namespace App\Services\EInvoicing;

use App\Contracts\EInvoiceAdapter;
use Illuminate\Contracts\Container\Container;

/**
 * The e-invoicing adapters this installation has (plan 3c.27), from `config('einvoicing.adapters')`. A company can choose only an
 * adapter that is configured, and a test-only adapter is never selectable outside the testing environment, whatever the config says.
 */
final class EInvoiceAdapters
{
    public function __construct(private readonly Container $container) {}

    /** @return array<string, EInvoiceAdapter> every registered adapter that may be used here, by key */
    public function selectable(): array
    {
        $out = [];
        /** @var array<string, class-string<EInvoiceAdapter>> $map */
        $map = (array) config('einvoicing.adapters', []);
        foreach ($map as $key => $class) {
            $adapter = $this->container->make($class);
            if ($adapter->key() !== $key || ($adapter->isTestOnly() && ! app()->environment('testing')) || ! $adapter->isConfigured()) {
                continue;
            }
            $out[$key] = $adapter;
        }

        return $out;
    }

    public function find(string $key): ?EInvoiceAdapter
    {
        return $this->selectable()[$key] ?? null;
    }
}
