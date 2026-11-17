<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Queries\Organisation\BillingUsageReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The plan and meters of the organisation. `level` is `ok` below 80 %, `warn` from 80 %, `over` from 100 % of the limit; a meter with no
 * limit (unlimited) is always `ok` with a null `ratio`. Only companies, users and api_clients are hard limits; the others never block.
 *
 * @mixin BillingUsageReport
 */
final class BillingUsageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['plan_code' => $this->planCode, 'meters' => $this->meters];
    }
}
