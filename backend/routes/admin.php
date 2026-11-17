<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminAuditController;
use App\Http\Controllers\Admin\ApprovalsController;
use App\Http\Controllers\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Admin\Auth\AdminPasswordSetController;
use App\Http\Controllers\Admin\Auth\AdminSessionController;
use App\Http\Controllers\Admin\Auth\AdminTwoFactorController;
use App\Http\Controllers\Admin\Billing\BillingStatusController;
use App\Http\Controllers\Admin\Billing\EntitlementOverridesController;
use App\Http\Controllers\Admin\Billing\SubscriptionsController;
use App\Http\Controllers\Admin\Compliance\ComplianceController;
use App\Http\Controllers\Admin\Ops\FlagsController;
use App\Http\Controllers\Admin\Ops\OpsController;
use App\Http\Controllers\Admin\Plans\AddonsController;
use App\Http\Controllers\Admin\Plans\CouponsController;
use App\Http\Controllers\Admin\Plans\PlansController;
use App\Http\Controllers\Admin\Staff\StaffController;
use App\Http\Controllers\Admin\Support\AnnouncementsController;
use App\Http\Controllers\Admin\Support\SupportController;
use App\Http\Controllers\Admin\Tenants\ProvisionTenantController;
use App\Http\Controllers\Admin\Tenants\ResendOwnerInvitationController;
use App\Http\Controllers\Admin\Tenants\TenantMembersController;
use App\Http\Controllers\Admin\Tenants\TenantNotesController;
use App\Http\Controllers\Admin\Tenants\TenantsController;
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

    // Tenants (Task 6)
    Route::post('tenants', ProvisionTenantController::class)->middleware('admin.can:tenants.provision')->name('tenants.store');
    Route::post('tenants/{tenant}/resend-owner-invitation', ResendOwnerInvitationController::class)->middleware('admin.can:tenants.provision')->name('tenants.resend-owner-invitation');

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
    // Entitlement overrides of a tenant (Task 10)
    Route::get('tenants/{tenant}/overrides', [EntitlementOverridesController::class, 'index'])->whereUuid('tenant')->middleware('admin.can:billing.view')->name('tenants.overrides.index');
    Route::middleware('admin.can:billing.manage')->group(function (): void {
        Route::put('tenants/{tenant}/overrides/{key}', [EntitlementOverridesController::class, 'put'])->whereUuid('tenant')->name('tenants.overrides.put');
        Route::delete('tenants/{tenant}/overrides/{key}', [EntitlementOverridesController::class, 'destroy'])->whereUuid('tenant')->name('tenants.overrides.destroy');
    });
    Route::middleware('admin.can:coupons.manage')->group(function (): void {
        Route::post('coupons', [CouponsController::class, 'store'])->name('coupons.store');
        Route::post('coupons/{coupon}/archive', [CouponsController::class, 'archive'])->name('coupons.archive');
    });

    // Tenants list and tenant page (Task 7)
    Route::middleware('admin.can:tenants.view')->group(function (): void {
        Route::get('tenants', [TenantsController::class, 'index'])->name('tenants.index');
        Route::get('tenants/{tenant}', [TenantsController::class, 'show'])->name('tenants.show');
        Route::get('tenants/{tenant}/users', [TenantsController::class, 'users'])->name('tenants.users');
        Route::get('tenants/{tenant}/companies', [TenantsController::class, 'companies'])->name('tenants.companies');
    });
    Route::get('tenants/{tenant}/audit', [TenantsController::class, 'audit'])->middleware('admin.can:audit.view')->name('tenants.audit');
    Route::middleware('admin.can:tenants.notes')->group(function (): void {
        Route::post('tenants/{tenant}/notes', [TenantNotesController::class, 'store'])->name('tenants.notes.store');
        Route::post('tenants/{tenant}/notes/{note}/pin', [TenantNotesController::class, 'pin'])->name('tenants.notes.pin');
    });
    // Compliance (Task 18)
    Route::middleware('admin.can:compliance.manage')->group(function (): void {
        Route::post('tenants/{tenant}/deletion', [ComplianceController::class, 'requestDeletion'])->name('tenants.deletion');
        Route::post('tenants/{tenant}/legal-hold', [ComplianceController::class, 'setLegalHold'])->name('tenants.legal-hold.set');
        Route::delete('tenants/{tenant}/legal-hold', [ComplianceController::class, 'requestHoldRelease'])->name('tenants.legal-hold.release');
        Route::put('tenants/{tenant}/archive-until', [ComplianceController::class, 'archiveUntil'])->name('tenants.archive-until');
        Route::get('compliance/retention', [ComplianceController::class, 'retention'])->name('compliance.retention');
        Route::get('compliance/gdpr-requests', [ComplianceController::class, 'gdprIndex'])->name('compliance.gdpr.index');
        Route::post('compliance/gdpr-requests', [ComplianceController::class, 'gdprStore'])->name('compliance.gdpr.store');
        Route::patch('compliance/gdpr-requests/{id}', [ComplianceController::class, 'gdprUpdate'])->name('compliance.gdpr.update');
    });

    Route::middleware('admin.can:tenants.lifecycle')->group(function (): void {
        Route::post('tenants/{tenant}/lifecycle', [TenantsController::class, 'lifecycle'])->name('tenants.lifecycle');
        Route::post('tenants/{tenant}/users/{user}/reset-two-factor', [TenantMembersController::class, 'resetTwoFactor'])->name('tenants.users.reset-two-factor');
        Route::post('tenants/{tenant}/users/{user}/disable', [TenantMembersController::class, 'disable'])->name('tenants.users.disable');
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

    // Support tools (Task 15)
    Route::get('search', [SupportController::class, 'search'])->middleware('admin.can:tenants.view')->name('search');
    Route::middleware('admin.can:ops.view')->group(function (): void {
        Route::get('jobs/failed', [SupportController::class, 'failedJobs'])->name('jobs.failed.index');
        Route::get('mail-log', [SupportController::class, 'mailLog'])->name('mail-log.index');
        Route::get('tenants/{tenant}/webhook-deliveries', [SupportController::class, 'webhookDeliveries'])->name('tenants.webhook-deliveries');
    });
    Route::middleware('admin.can:ops.manage')->group(function (): void {
        Route::post('jobs/failed/{uuid}/retry', [SupportController::class, 'retry'])->whereUuid('uuid')->name('jobs.failed.retry');
        Route::delete('jobs/failed/{uuid}', [SupportController::class, 'forget'])->whereUuid('uuid')->name('jobs.failed.forget');
    });
    Route::middleware('admin.can:announcements.manage')->group(function (): void {
        Route::get('announcements', [AnnouncementsController::class, 'index'])->name('announcements.index');
        Route::post('announcements', [AnnouncementsController::class, 'store'])->name('announcements.store');
        Route::put('announcements/{id}', [AnnouncementsController::class, 'update'])->whereUuid('id')->name('announcements.update');
        Route::delete('announcements/{id}', [AnnouncementsController::class, 'destroy'])->whereUuid('id')->name('announcements.destroy');
    });

    // Platform operations and feature flags (Task 17)
    Route::middleware('admin.can:ops.view')->group(function (): void {
        Route::get('ops/health', [OpsController::class, 'health'])->name('ops.health');
        Route::get('ops/maintenance', [OpsController::class, 'maintenance'])->name('ops.maintenance');
        Route::get('tenants/{tenant}/rate-limit', [OpsController::class, 'rateLimit'])->name('tenants.rate-limit.show');
        Route::get('flags', [FlagsController::class, 'index'])->name('flags.index');
    });
    Route::middleware('admin.can:ops.manage')->group(function (): void {
        Route::put('ops/maintenance', [OpsController::class, 'setMaintenance'])->name('ops.maintenance.set');
        Route::put('tenants/{tenant}/rate-limit', [OpsController::class, 'setRateLimit'])->name('tenants.rate-limit.set');
        Route::delete('tenants/{tenant}/rate-limit', [OpsController::class, 'clearRateLimit'])->name('tenants.rate-limit.clear');
    });
    Route::middleware('admin.can:flags.manage')->group(function (): void {
        Route::put('flags/{name}', [FlagsController::class, 'setGlobally'])->name('flags.set');
        Route::put('tenants/{tenant}/flags/{name}', [FlagsController::class, 'setForTenant'])->name('tenants.flags.set');
    });
});
