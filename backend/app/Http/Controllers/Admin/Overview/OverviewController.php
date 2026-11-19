<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Overview;

use App\Http\Controllers\Controller;
use App\Queries\Admin\Overview\PlatformKpis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The operator dashboard figures (plan Task 19). Read-only; gated by `admin.can:billing.view` on the route. */
final class OverviewController extends Controller
{
    public function __invoke(Request $request, PlatformKpis $kpis): JsonResponse
    {
        $data = $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:24']]);

        return response()->json(['data' => $kpis((int) ($data['months'] ?? 12))]);
    }
}
