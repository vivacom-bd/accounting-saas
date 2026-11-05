<?php

declare(strict_types=1);

use App\Contracts\EInvoiceAdapter;

return [
    /*
     * Adapter key => class (plan 3c.27). EMPTY on purpose: no adapter for any authority ships yet. In particular there is no Kenya eTIMS
     * adapter (plan 3c.28 is blocked: it needs a KRA sandbox and specification), and nothing may claim it works. A real adapter is added
     * here when it exists and has been tested against its authority. The fake adapter is registered by tests only and is refused
     * outside the testing environment.
     *
     * @var array<string, class-string<EInvoiceAdapter>>
     */
    'adapters' => [],
];
