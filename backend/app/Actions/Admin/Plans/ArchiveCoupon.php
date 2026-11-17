<?php

declare(strict_types=1);

namespace App\Actions\Admin\Plans;

use App\Models\Billing\Coupon;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/** Stops a coupon being redeemed. Existing redemptions are untouched. */
final class ArchiveCoupon
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function __invoke(AdminActor $actor, Coupon $coupon): Coupon
    {
        return AdminTransaction::run(function () use ($actor, $coupon): Coupon {
            $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_active) {
                $locked->forceFill(['is_active' => false])->save();
                $this->audit->record($actor, 'coupon.archived', null, 'coupon', $locked->id, before: ['is_active' => true], after: ['is_active' => false, 'code' => $locked->code]);
            }

            return $locked;
        });
    }
}
