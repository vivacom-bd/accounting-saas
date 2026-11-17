<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Support\Admin\AdminTransaction;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The active owners of an organisation, read on the platform connection (the platform role may read the non-secret
 * user columns), and the mail sent to them as on-demand notifications (plan Task 12).
 */
final class TenantOwners
{
    /** @return list<string> */
    public function emails(string $tenantId): array
    {
        return array_values(array_map(
            fn (object $r): string => (string) ((array) $r)['email'],
            AdminTransaction::connection()->select(
                "SELECT DISTINCT u.email FROM tenant_users tu JOIN users u ON u.id = tu.user_id WHERE tu.tenant_id = ? AND tu.role = 'owner' AND tu.status = 'active' ORDER BY u.email",
                [$tenantId],
            ),
        ));
    }

    /** Sends `$notification` to every active owner now; a failed delivery is reported, never thrown (the caller's state change stands). */
    public function notify(string $tenantId, object $notification): void
    {
        foreach ($this->emails($tenantId) as $email) {
            try {
                Notification::route('mail', $email)->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
