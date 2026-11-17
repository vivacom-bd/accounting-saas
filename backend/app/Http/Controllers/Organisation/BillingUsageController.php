<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organisation;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingUsageResource;
use App\Models\Platform\Tenant;
use App\Queries\Organisation\BillingUsage;
use Illuminate\Routing\Attributes\Controllers\Authorize;

/** The organisation's plan and usage meters (plan Task 10). */
final class BillingUsageController extends Controller
{
    #[Authorize('viewBilling', Tenant::class)]
    public function show(BillingUsage $usage): BillingUsageResource
    {
        return new BillingUsageResource($usage());
    }
}
