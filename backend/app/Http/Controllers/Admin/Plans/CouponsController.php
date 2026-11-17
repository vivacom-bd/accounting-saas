<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Plans;

use App\Actions\Admin\Plans\ArchiveCoupon;
use App\Actions\Admin\Plans\CreateCoupon;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Models\Billing\Coupon;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Coupons (plan Task 9). Routes are gated by `admin.can:coupons.manage`. */
final class CouponsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Coupon::query()->orderByDesc('created_at')->orderBy('code')->get()->map(fn (Coupon $c): array => self::view($c))->all()]);
    }

    public function store(Request $request, CreateCoupon $create): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48'],
            'percent' => ['nullable', 'integer'],
            'amount' => ['nullable', 'integer'],
            'currency' => ['nullable', 'string', 'size:3'],
            'duration' => ['required', 'string', 'max:16'],
            'duration_months' => ['nullable', 'integer'],
            'restrictions' => ['nullable', 'array'],
            'max_redemptions' => ['nullable', 'integer'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $coupon = $create(RequestAdminActor::from($request), [
            'code' => $data['code'],
            'percent' => $data['percent'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : null,
            'duration' => $data['duration'],
            'duration_months' => $data['duration_months'] ?? null,
            'restrictions' => $request->array('restrictions'),
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'expires_at' => isset($data['expires_at']) ? new DateTimeImmutable($data['expires_at']) : null,
        ]);

        return response()->json(['data' => self::view($coupon)], 201);
    }

    public function archive(Request $request, string $coupon, ArchiveCoupon $archive): JsonResponse
    {
        $archived = $archive(RequestAdminActor::from($request), Coupon::query()->findOrFail($coupon));

        return response()->json(['data' => self::view($archived)]);
    }

    /** @return array<string, mixed> */
    private static function view(Coupon $c): array
    {
        return [
            'id' => $c->id, 'code' => $c->code, 'percent' => $c->percent, 'amount' => $c->amount, 'currency' => $c->currency,
            'duration' => $c->duration, 'duration_months' => $c->duration_months, 'restrictions' => $c->restrictions,
            'max_redemptions' => $c->max_redemptions, 'redeemed_count' => $c->redeemed_count,
            'expires_at' => $c->expires_at?->format(DATE_ATOM), 'stripe_coupon_id' => $c->stripe_coupon_id, 'is_active' => $c->is_active,
        ];
    }
}
