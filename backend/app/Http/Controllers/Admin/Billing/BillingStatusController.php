<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Contracts\BillingGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** `GET /api/admin/billing/status`: whether Stripe collection is available on this installation (plan G2). */
final class BillingStatusController extends Controller
{
    public function __invoke(BillingGateway $gateway): JsonResponse
    {
        return response()->json(['stripe_configured' => $gateway->isConfigured()]);
    }
}
