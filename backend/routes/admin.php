<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Admin\Auth\AdminSessionController;
use App\Http\Controllers\Admin\Auth\AdminTwoFactorController;
use App\Http\Controllers\Admin\Plans\AddonsController;
use App\Http\Controllers\Admin\Plans\CouponsController;
use App\Http\Controllers\Admin\Plans\PlansController;
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

Route::middleware('admin.auth')->group(function (): void {
    Route::get('session', [AdminSessionController::class, 'show'])->name('session');

    // Plan catalogue (Task 9)
    Route::middleware('admin.can:plans.manage')->group(function (): void {
        Route::get('plans', [PlansController::class, 'index'])->name('plans.index');
        Route::post('plans', [PlansController::class, 'store'])->name('plans.store');
        Route::post('plans/{plan}/versions', [PlansController::class, 'publish'])->name('plans.versions.store');
        Route::post('plans/{plan}/archive', [PlansController::class, 'archive'])->name('plans.archive');
        Route::get('addons', [AddonsController::class, 'index'])->name('addons.index');
        Route::put('addons/{code}', [AddonsController::class, 'upsert'])->name('addons.upsert');
    });
    Route::middleware('admin.can:coupons.manage')->group(function (): void {
        Route::get('coupons', [CouponsController::class, 'index'])->name('coupons.index');
        Route::post('coupons', [CouponsController::class, 'store'])->name('coupons.store');
        Route::post('coupons/{coupon}/archive', [CouponsController::class, 'archive'])->name('coupons.archive');
    });
});
