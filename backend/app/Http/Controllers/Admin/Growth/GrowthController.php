<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Growth;

use App\Actions\Admin\Growth\ConvertWithQuote;
use App\Actions\Admin\Growth\SetNudgesEnabled;
use App\Domain\Billing\Prices;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Queries\Admin\Growth\TrialFunnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Trials and growth (plan Task 16, 10 §3.6): the activation funnel, the live trial list, nudges and quoted conversion. */
final class GrowthController extends Controller
{
    public function funnel(Request $request, TrialFunnel $funnel): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $funnel->funnel($data['from'] ?? null, $data['to'] ?? null)]);
    }

    public function trials(TrialFunnel $funnel): JsonResponse
    {
        return response()->json(['data' => $funnel->trials()]);
    }

    public function convert(Request $request, ConvertWithQuote $convert): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', 'max:32'],
            'interval' => ['required', Rule::in(['month', 'year'])],
            'currency' => ['required', Rule::in(Prices::CURRENCIES)],
            'custom_amount' => ['required', 'integer', 'min:0'],
            'collection' => ['nullable', Rule::in(['offline', 'stripe'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $sub = $convert->handle(
            RequestAdminActor::from($request), $request->route('tenant'), $data['plan_code'], $data['interval'], $data['currency'],
            (int) $data['custom_amount'], $data['collection'] ?? 'offline', $data['note'] ?? null,
        );

        return response()->json(['data' => [
            'id' => $sub->id, 'tenant_id' => $sub->tenant_id, 'plan_version_id' => $sub->plan_version_id,
            'status' => $sub->status, 'interval' => $sub->interval, 'currency' => $sub->currency, 'custom_amount' => $sub->custom_amount,
        ]], 201);
    }

    public function setNudges(Request $request, SetNudgesEnabled $set): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return response()->json(['data' => $set->handle(RequestAdminActor::from($request), (bool) $data['enabled'])]);
    }
}
