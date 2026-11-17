<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Billing;

use App\Actions\Admin\Billing\RemoveEntitlementOverride;
use App\Actions\Admin\Billing\SetEntitlementOverride;
use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Entitlement overrides of one tenant (plan Task 10). Reads need `billing.view`, writes `billing.manage`. */
final class EntitlementOverridesController extends Controller
{
    public function index(string $tenant): JsonResponse
    {
        $db = AdminTransaction::connection();
        if (! $db->table('tenants')->where('id', $tenant)->exists()) {
            throw DomainRuleViolation::single('not_found', 'There is no such organisation.');
        }
        $rows = $db->table('tenant_entitlement_overrides')->where('tenant_id', $tenant)->orderBy('key')->get();

        return response()->json(['data' => $rows->map(fn (object $r): array => [
            'key' => $r->key, 'value' => json_decode((string) $r->value, true), 'reason' => $r->reason, 'set_by' => $r->set_by,
            'expires_at' => $r->expires_at === null ? null : (new DateTimeImmutable($r->expires_at))->format(DATE_ATOM),
            'active' => $r->expires_at === null || new DateTimeImmutable($r->expires_at) > new DateTimeImmutable,
        ])->all()]);
    }

    public function put(Request $request, string $tenant, string $key, SetEntitlementOverride $set, Clock $clock): JsonResponse
    {
        $data = $request->validate([
            'value' => ['present'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'expires_at' => ['nullable', 'date'],
        ]);
        try {
            SetEntitlementOverride::validate($key, $data['value']);
        } catch (DomainRuleViolation $e) {
            throw ValidationException::withMessages([$e->errorCode === 'override_key_unknown' ? 'key' : 'value' => [$e->getMessage()]]);
        }
        $expires = isset($data['expires_at']) ? new DateTimeImmutable($data['expires_at']) : null;
        if ($expires !== null && $expires <= $clock->now()) {
            throw ValidationException::withMessages(['expires_at' => ['The expiry must be in the future.']]);
        }

        return response()->json(['data' => $set(RequestAdminActor::from($request), $tenant, $key, $data['value'], $data['reason'], $expires, $clock->now())]);
    }

    public function destroy(Request $request, string $tenant, string $key, RemoveEntitlementOverride $remove): Response
    {
        $remove(RequestAdminActor::from($request), $tenant, $key);

        return response()->noContent();
    }
}
