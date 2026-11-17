<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Plans;

use App\Actions\Admin\Plans\UpsertAddon;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Models\Billing\PlanAddon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Add-ons (plan Task 9). Routes are gated by `admin.can:plans.manage`. */
final class AddonsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => PlanAddon::query()->orderBy('code')->get()->map(fn (PlanAddon $a): array => self::view($a))->all()]);
    }

    public function upsert(Request $request, string $code, UpsertAddon $upsert): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', 'string', 'max:16'],
            'price' => ['required', 'array'],
            'unit_label' => ['nullable', 'string', 'max:80'],
        ]);
        if (preg_match('/^[a-z][a-z0-9_]{1,47}$/', $code) !== 1) {
            abort(404);
        }

        $addon = $upsert(RequestAdminActor::from($request), $code, $data['name'], $data['kind'], $request->array('price'), $data['unit_label'] ?? null);

        return response()->json(['data' => self::view($addon)]);
    }

    /** @return array<string, mixed> */
    private static function view(PlanAddon $a): array
    {
        return [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'kind' => $a->kind, 'price' => $a->price,
            'unit_label' => $a->unit_label, 'stripe_price_id' => $a->stripe_price_id, 'is_active' => $a->is_active,
        ];
    }
}
