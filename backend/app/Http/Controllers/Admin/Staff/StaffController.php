<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Staff;

use App\Actions\Admin\Staff\DeactivateStaff;
use App\Actions\Admin\Staff\InviteStaff;
use App\Actions\Admin\Staff\ResetStaffTwoFactor;
use App\Actions\Admin\Staff\UpdateStaff;
use App\Domain\Admin\AdminRole;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminStaffResource;
use App\Http\Support\AdminSession;
use App\Http\Support\RequestAdminActor;
use App\Models\Admin\AdminUser;
use App\Models\Casts\PgInetArray;
use App\Queries\Admin\ListStaff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Staff management (`staff.manage`): controllers validate, the Actions decide. */
final class StaffController extends Controller
{
    public function index(ListStaff $staff): JsonResponse
    {
        return response()->json(['data' => $staff()->map(fn (AdminUser $a): array => AdminStaffResource::make($a))->all()]);
    }

    public function store(Request $request, InviteStaff $invite): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(AdminRole::class)],
        ]);
        $user = AdminSession::user();
        $inviter = $user === null ? 'A colleague' : $user->name;

        $admin = $invite->handle(RequestAdminActor::from($request), $data['email'], $data['name'], AdminRole::from($data['role']), $inviter);

        return response()->json(['data' => AdminStaffResource::make($admin)], 201);
    }

    public function update(Request $request, UpdateStaff $update, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::enum(AdminRole::class)],
            'ip_allowlist' => ['sometimes', 'array', 'max:50'],
            'ip_allowlist.*' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! PgInetArray::isAddressOrBlock($value)) {
                    $fail('Each entry must be an IPv4 or IPv6 address or a CIDR block.');
                }
            }],
        ]);
        $changes = [];
        if (isset($data['name'])) {
            $changes['name'] = $data['name'];
        }
        if (isset($data['role'])) {
            $changes['role'] = AdminRole::from($data['role']);
        }
        if (isset($data['ip_allowlist'])) {
            $changes['ip_allowlist'] = array_values($data['ip_allowlist']);
        }

        return response()->json(['data' => AdminStaffResource::make($update->handle(RequestAdminActor::from($request), self::uuid($id), $changes))]);
    }

    public function deactivate(Request $request, DeactivateStaff $deactivate, string $id): JsonResponse
    {
        return response()->json(['data' => AdminStaffResource::make($deactivate->handle(RequestAdminActor::from($request), self::uuid($id)))]);
    }

    public function resetTwoFactor(Request $request, ResetStaffTwoFactor $reset, string $id): JsonResponse
    {
        return response()->json(['data' => AdminStaffResource::make($reset->handle(RequestAdminActor::from($request), self::uuid($id)))]);
    }

    private static function uuid(string $id): string
    {
        return Str::isUuid($id) ? $id : throw DomainRuleViolation::single('not_found', 'Staff member not found.');
    }
}
