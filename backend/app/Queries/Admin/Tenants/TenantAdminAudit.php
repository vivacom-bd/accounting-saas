<?php

declare(strict_types=1);

namespace App\Queries\Admin\Tenants;

use App\Http\Support\CursorPage;
use App\Queries\Admin\ListAdminAudit;
use Illuminate\Http\Request;

/** `GET /api/admin/tenants/{id}/audit`: the admin audit log narrowed to one tenant, newest first. */
final class TenantAdminAudit
{
    public function __construct(private readonly ListAdminAudit $audit) {}

    public function __invoke(Request $request, string $tenantId): CursorPage
    {
        return ($this->audit)($request, ['tenant' => $tenantId]);
    }
}
