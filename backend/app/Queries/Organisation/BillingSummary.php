<?php

declare(strict_types=1);

namespace App\Queries\Organisation;

use App\Services\Billing\Entitlements;
use Illuminate\Database\DatabaseManager;

/**
 * What the organisation sees of its own plan (plan Task 24, 4b.8): the plan code, the usage meters and — through
 * sysops.tenant_billing_summary() — its live subscription and recent invoices. The app role cannot read the billing
 * tables; that function is bound to app.tenant_id. Money stays integer minor units.
 */
final class BillingSummary
{
    public function __construct(private readonly Entitlements $entitlements, private readonly DatabaseManager $db) {}

    /** @return array{plan_code: string|null, meters: list<array<string, mixed>>, subscription: array<string, mixed>|null, invoices: list<array<string, mixed>>} */
    public function __invoke(): array
    {
        $row = $this->db->connection()->selectOne('SELECT sysops.tenant_billing_summary() AS s');
        /** @var array{subscription?: array<string, mixed>|null, invoices?: list<array<string, mixed>>} $data */
        $data = json_decode((string) ($row->s ?? '{}'), true, flags: JSON_THROW_ON_ERROR) ?: [];

        return [
            'plan_code' => $this->entitlements->snapshot()['plan_code'],
            'meters' => $this->entitlements->meters(),
            'subscription' => $data['subscription'] ?? null,
            'invoices' => $data['invoices'] ?? [],
        ];
    }
}
