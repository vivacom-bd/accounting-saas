<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Actions\Billing\ApplyCoupon;
use App\Actions\Billing\CancelSubscription;
use App\Actions\Billing\ChangePlan;
use App\Actions\Billing\ConvertTrial;
use App\Actions\Billing\ExtendTrial;
use App\Actions\Billing\PauseSubscription;
use App\Actions\Billing\PreviewPlanChange;
use App\Actions\Billing\ResumeSubscription;
use App\Actions\Billing\Subscribe;
use App\Domain\Billing\Prices;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Models\Billing\Subscription;
use App\Queries\Admin\TenantSubscriptionView;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The Subscription tab of a tenant (plan Task 11). Reads need `billing.view`, writes `billing.manage` (set on the
 * routes). The tenant is resolved here, explicitly, from the path; a malformed or unknown id is a plain 404.
 */
final class SubscriptionsController extends Controller
{
    public function __construct(private readonly TenantSubscriptionView $view) {}

    public function show(string $tenant): JsonResponse
    {
        return $this->respond($this->view->forTenant(self::tenantId($tenant)));
    }

    public function preview(Request $request, string $tenant, PreviewPlanChange $preview): JsonResponse
    {
        $data = $request->validate(['plan_code' => ['required', 'string', 'max:32'], 'interval' => ['nullable', Rule::in(['month', 'year'])]]);

        return response()->json(['data' => $preview(self::tenantId($tenant), $data['plan_code'], $data['interval'] ?? null)]);
    }

    public function store(Request $request, string $tenant, Subscribe $subscribe): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', 'max:32'],
            'interval' => ['required', Rule::in(['month', 'year'])],
            'currency' => ['required', Rule::in(Prices::CURRENCIES)],
            'collection' => ['required', Rule::in(['stripe', 'offline'])],
            'custom_amount' => ['nullable', 'integer', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:48'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $id = self::tenantId($tenant);

        $subscribe(
            RequestAdminActor::from($request), $id, $data['plan_code'], $data['interval'], $data['currency'], $data['collection'],
            isset($data['custom_amount']) ? (int) $data['custom_amount'] : null, $data['coupon_code'] ?? null, $data['reason'] ?? null,
        );

        return $this->respond($this->view->forTenant($id), 201);
    }

    public function changePlan(Request $request, string $tenant, ChangePlan $change): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', 'max:32'],
            'interval' => ['nullable', Rule::in(['month', 'year'])],
            'custom_amount' => ['nullable', 'integer', 'min:0'],
            'prorate' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $id = self::tenantId($tenant);

        $change(
            RequestAdminActor::from($request), $id, $data['plan_code'], $data['interval'] ?? null,
            isset($data['custom_amount']) ? (int) $data['custom_amount'] : null, (bool) ($data['prorate'] ?? true), $data['reason'] ?? null,
        );

        return $this->respond($this->view->forTenant($id));
    }

    public function cancel(Request $request, string $tenant, CancelSubscription $cancel): JsonResponse
    {
        $data = $request->validate(['at_period_end' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);
        $id = self::tenantId($tenant);

        $cancel(RequestAdminActor::from($request), $id, (bool) $data['at_period_end'], $data['reason'] ?? null);

        return $this->respond($this->view->forTenant($id));
    }

    public function resume(Request $request, string $tenant, ResumeSubscription $resume): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $id = self::tenantId($tenant);

        $resume(RequestAdminActor::from($request), $id, $data['reason'] ?? null);

        return $this->respond($this->view->forTenant($id));
    }

    public function pause(Request $request, string $tenant, PauseSubscription $pause): JsonResponse
    {
        $data = $request->validate(['until' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:500']]);
        $id = self::tenantId($tenant);

        $pause(RequestAdminActor::from($request), $id, isset($data['until']) ? new DateTimeImmutable($data['until'].' UTC') : null, $data['reason'] ?? null);

        return $this->respond($this->view->forTenant($id));
    }

    public function extendTrial(Request $request, string $tenant, ExtendTrial $extend): JsonResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:'.ExtendTrial::MAX_DAYS], 'reason' => ['nullable', 'string', 'max:500']]);
        $id = self::tenantId($tenant);

        $extend(RequestAdminActor::from($request), $id, (int) $data['days'], $data['reason'] ?? null);

        return $this->respond($this->view->forTenant($id));
    }

    public function convertTrial(Request $request, string $tenant, ConvertTrial $convert): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $id = self::tenantId($tenant);

        $convert(RequestAdminActor::from($request), $id, $data['reason'] ?? null);

        return $this->respond($this->view->forTenant($id));
    }

    public function applyCoupon(Request $request, string $tenant, ApplyCoupon $apply): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:48'], 'reason' => ['nullable', 'string', 'max:500']]);
        $id = self::tenantId($tenant);

        $apply(RequestAdminActor::from($request), $id, $data['code'], $data['reason'] ?? null);

        return $this->respond($this->view->forTenant($id));
    }

    /** @param  array<string, mixed>|null  $data */
    private function respond(?array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    private static function tenantId(string $id): string
    {
        return Str::isUuid($id) ? $id : throw DomainRuleViolation::single('not_found', 'Organisation not found.');
    }
}
