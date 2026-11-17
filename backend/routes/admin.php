<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminAuditController;
use App\Http\Controllers\Admin\ApprovalsController;
use App\Http\Controllers\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Admin\Auth\AdminPasswordSetController;
use App\Http\Controllers\Admin\Auth\AdminSessionController;
use App\Http\Controllers\Admin\Auth\AdminTwoFactorController;
use App\Http\Controllers\Admin\Billing\BillingStatusController;
use App\Http\Controllers\Admin\Billing\SubscriptionsController;
use App\Http\Controllers\Admin\Plans\AddonsController;
use App\Http\Controllers\Admin\Plans\CouponsController;
use App\Http\Controllers\Admin\Plans\PlansController;
use App\Http\Controllers\Admin\Staff\StaffController;
use Illuminate\Support\Facades\Route;

/*
 * Platform admin API (10_SaaS_Admin, plan Task 3). Registered in bootstrap/app.php under the `admin` middleware group
 * and the `api/admin` prefix, host-locked by config('admin.domain') when set. Protected routes go inside
 * `admin.auth` (TOTP-complete sign-in, idle timeout) and declare `admin.can:<ability>`.
 */

Route::get('csrf-cookie', [AdminLoginController::class, 'csrfCookie'])->name('csrf-cookie');
Route::post('auth/login', [AdminLoginController::class, 'store'])->middleware('throttle:admin-login')->name('auth.login');
Route::post('auth/logout', [AdminLoginController::class, 'destroy'])->name('auth.logout');
Route::post('auth/two-factor/enrol', [AdminTwoFactorController::class, 'enrol'])->name('auth.two-factor.enrol');
Route::post('auth/two-factor/confirm', [AdminTwoFactorController::class, 'confirm'])->middleware('throttle:admin-two-factor')->name('auth.two-factor.confirm');
Route::post('auth/two-factor', [AdminTwoFactorController::class, 'verify'])->middleware('throttle:admin-two-factor')->name('auth.two-factor');
Route::post('auth/set-password', [AdminPasswordSetController::class, 'store'])->middleware('throttle:admin-set-password')->name('auth.set-password');

Route::middleware('admin.auth')->group(function (): void {
    Route::get('session', [AdminSessionController::class, 'show'])->name('session');

    Route::get('approvals', [ApprovalsController::class, 'index'])->name('approvals.index');
    Route::post('approvals/{id}/approve', [ApprovalsController::class, 'approve'])->middleware('admin.can:approvals.decide')->name('approvals.approve');
    Route::post('approvals/{id}/reject', [ApprovalsController::class, 'reject'])->middleware('admin.can:approvals.decide')->name('approvals.reject');

    Route::middleware('admin.can:staff.manage')->group(function (): void {
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::patch('staff/{id}', [StaffController::class, 'update'])->name('staff.update');
        Route::post('staff/{id}/deactivate', [StaffController::class, 'deactivate'])->name('staff.deactivate');
        Route::post('staff/{id}/reset-two-factor', [StaffController::class, 'resetTwoFactor'])->name('staff.reset-two-factor');
    });

    Route::middleware('admin.can:audit.view')->group(function (): void {
        Route::get('audit', [AdminAuditController::class, 'index'])->name('audit.index');
        Route::post('audit/verify', [AdminAuditController::class, 'verify'])->name('audit.verify');
    });

    // Plan catalogue (Task 9)
    Route::middleware('admin.can:plans.view')->group(function (): void {
        Route::get('plans', [PlansController::class, 'index'])->name('plans.index');
        Route::get('addons', [AddonsController::class, 'index'])->name('addons.index');
        Route::get('coupons', [CouponsController::class, 'index'])->name('coupons.index');
    });
    Route::middleware('admin.can:plans.manage')->group(function (): void {
        Route::post('plans', [PlansController::class, 'store'])->name('plans.store');
        Route::post('plans/{plan}/versions', [PlansController::class, 'publish'])->name('plans.versions.store');
        Route::post('plans/{plan}/archive', [PlansController::class, 'archive'])->name('plans.archive');
        Route::put('addons/{code}', [AddonsController::class, 'upsert'])->name('addons.upsert');
    });
    Route::middleware('admin.can:coupons.manage')->group(function (): void {
        Route::post('coupons', [CouponsController::class, 'store'])->name('coupons.store');
        Route::post('coupons/{coupon}/archive', [CouponsController::class, 'archive'])->name('coupons.archive');
    });

    // Billing gateway and subscriptions (Task 11)
    Route::middleware('admin.can:billing.view')->group(function (): void {
        Route::get('billing/status', BillingStatusController::class)->name('billing.status');
        Route::get('tenants/{tenant}/subscription', [SubscriptionsController::class, 'show'])->name('tenants.subscription.show');
        Route::post('tenants/{tenant}/subscription/preview', [SubscriptionsController::class, 'preview'])->name('tenants.subscription.preview');
    });
    Route::middleware('admin.can:billing.manage')->group(function (): void {
        Route::post('tenants/{tenant}/subscription', [SubscriptionsController::class, 'store'])->name('tenants.subscription.store');
        Route::post('tenants/{tenant}/subscription/change-plan', [SubscriptionsController::class, 'changePlan'])->name('tenants.subscription.change-plan');
        Route::post('tenants/{tenant}/subscription/cancel', [SubscriptionsController::class, 'cancel'])->name('tenants.subscription.cancel');
        Route::post('tenants/{tenant}/subscription/resume', [SubscriptionsController::class, 'resume'])->name('tenants.subscription.resume');
        Route::post('tenants/{tenant}/subscription/pause', [SubscriptionsController::class, 'pause'])->name('tenants.subscription.pause');
        Route::post('tenants/{tenant}/subscription/extend-trial', [SubscriptionsController::class, 'extendTrial'])->name('tenants.subscription.extend-trial');
        Route::post('tenants/{tenant}/subscription/convert-trial', [SubscriptionsController::class, 'convertTrial'])->name('tenants.subscription.convert-trial');
        Route::post('tenants/{tenant}/subscription/apply-coupon', [SubscriptionsController::class, 'applyCoupon'])->name('tenants.subscription.apply-coupon');
    });
});
