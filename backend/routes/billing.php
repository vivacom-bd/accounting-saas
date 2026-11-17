<?php

declare(strict_types=1);

use App\Http\Controllers\Billing\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Stripe's webhook (plan Task 12). Registered outside /api/v1 and the `api` middleware group on purpose: no session,
 * no CSRF, no tenant resolution, no metering. The signature is checked in the controller; only the throttle applies.
 */
Route::post('api/stripe/webhook', StripeWebhookController::class)->middleware('throttle:120,1')->name('stripe.webhook');
