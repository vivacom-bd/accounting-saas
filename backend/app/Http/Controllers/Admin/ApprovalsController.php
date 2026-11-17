<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Approvals\DecideApproval;
use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Models\Admin\AdminApproval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Two-person approvals: list, approve, reject. Authorization of who may decide lives in DecideApproval. */
final class ApprovalsController extends Controller
{
    private const array STATUSES = ['pending', 'approved', 'rejected', 'executed', 'expired'];

    public function index(Request $request, Clock $clock): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'in:'.implode(',', self::STATUSES)]]);
        $now = $clock->now();
        $query = AdminApproval::query()->orderByDesc('created_at')->orderByDesc('id');

        $status = $data['status'] ?? null;
        if ($status === 'expired') {
            $query->whereIn('status', ['pending', 'approved'])->where('expires_at', '<=', $now);
        } elseif ($status !== null) {
            $query->where('status', $status);
            if (in_array($status, ['pending', 'approved'], true)) {
                $query->where('expires_at', '>', $now);
            }
        }

        return response()->json(['data' => $query->limit(200)->get()->map(fn (AdminApproval $a): array => self::resource($a, $clock))->all()]);
    }

    public function approve(Request $request, DecideApproval $decide, Clock $clock, string $id): JsonResponse
    {
        return $this->decide($request, $decide, $clock, $id, true, null);
    }

    public function reject(Request $request, DecideApproval $decide, Clock $clock, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->decide($request, $decide, $clock, $id, false, $data['reason']);
    }

    private function decide(Request $request, DecideApproval $decide, Clock $clock, string $id, bool $approve, ?string $reason): JsonResponse
    {
        if (! Str::isUuid($id)) {
            throw DomainRuleViolation::single('not_found', 'Approval not found.');
        }

        return response()->json(['data' => self::resource($decide->handle(RequestAdminActor::from($request), $id, $approve, $reason), $clock)]);
    }

    /** @return array<string, mixed> */
    public static function resource(AdminApproval $a, Clock $clock): array
    {
        return [
            'id' => $a->id,
            'kind' => $a->kind->value,
            'tenant_id' => $a->tenant_id,
            'subject' => $a->subject,
            'reason' => $a->reason,
            'requested_by' => $a->requested_by,
            'approved_by' => $a->approved_by,
            'status' => $a->effectiveStatus($clock->now()),
            'rejection_reason' => $a->rejection_reason,
            'expires_at' => $a->expires_at->format('c'),
            'decided_at' => $a->decided_at?->format('c'),
            'executed_at' => $a->executed_at?->format('c'),
            'created_at' => $a->created_at?->format('c'),
        ];
    }
}
