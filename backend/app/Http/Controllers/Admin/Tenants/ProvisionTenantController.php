<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Tenants;

use App\Actions\Admin\Tenants\Inputs\ProvisionTenantInput;
use App\Actions\Admin\Tenants\ProvisionTenant;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProvisionTenantRequest;
use App\Http\Resources\Admin\AdminTenantResource;
use App\Http\Support\RequestAdminActor;
use Illuminate\Http\JsonResponse;
use Ramsey\Uuid\Uuid;

/**
 * Provision a tenant (plan Task 6). The shared idempotency middleware is tenant-scoped (RLS), so admin routes derive the
 * tenant id from the staff member and the key instead: the same key finds the tenant it made and answers 200.
 */
final class ProvisionTenantController extends Controller
{
    /** Fixed namespace for the key-derived tenant ids; never change it. */
    private const string KEY_NAMESPACE = '5e0b7c1a-3f1d-4f4e-9a52-7b1f0a6d2c11';

    public function __invoke(ProvisionTenantRequest $request, ProvisionTenant $provision): JsonResponse
    {
        $admin = RequestAdminActor::from($request);
        $key = $request->headers->get('Idempotency-Key');
        if ($key === null || $key === '') {
            throw DomainRuleViolation::single('idempotency_key_required', 'Send an Idempotency-Key header with every change.');
        }
        if (preg_match('/^[\x21-\x7E]{1,255}$/', $key) !== 1) {
            throw DomainRuleViolation::single('idempotency_key_invalid', 'The Idempotency-Key must be 1 to 255 visible ASCII characters.');
        }

        $trialDays = $request->input('trial_days');
        $result = $provision->handle($admin, new ProvisionTenantInput(
            tenantId: strtolower(Uuid::uuid5(self::KEY_NAMESPACE, $admin->id.':'.$key)->toString()),
            organisationName: trim($request->string('organisation_name')->toString()),
            country: strtoupper($request->string('country')->toString()),
            ownerEmail: $request->string('owner_email')->toString(),
            ownerName: trim($request->string('owner_name')->toString()),
            planCode: $request->string('plan_code')->toString(),
            billing: $request->string('billing')->toString(),
            trialDays: $trialDays === null ? null : (int) $trialDays,
            note: $request->filled('note') ? $request->string('note')->toString() : null,
        ));

        return response()->json(['data' => AdminTenantResource::make($result)], $result->created ? 201 : 200);
    }
}
