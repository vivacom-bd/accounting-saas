<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions (10_SaaS_Admin §6; plan Task 11, T5): our own tables, not Cashier's. Platform tables (tenant_id is a
 * plain foreign key, no RLS; allowlisted in Rls::GLOBAL_TABLES); only `ledgerly_platform` touches them. The tenant app
 * learns its plan and limits through sysops functions (Task 10), never from these rows.
 *
 * `updated_from_event_at` is the `created` time of the newest Stripe event that changed the row (Task 12 ignores older
 * events). `stripe_prices` holds the Stripe price id of each plan version x currency x interval, written by
 * `stripe:sync-catalogue`.
 */
return new class extends Migration
{
    private const array TABLES = ['subscriptions', 'subscription_addons', 'subscription_events', 'stripe_prices'];

    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('plan_version_id');
            $table->string('collection', 8);
            $table->string('interval', 8);
            $table->char('currency', 3);
            $table->bigInteger('custom_amount')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('status', 16);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampTz('cancel_at')->nullable();
            $table->timestampTz('pause_until')->nullable();
            $table->uuid('coupon_id')->nullable();
            $table->string('stripe_subscription_id', 64)->nullable()->unique();
            $table->string('stripe_customer_id', 64)->nullable();
            $table->timestampTz('updated_from_event_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('plan_version_id')->references('id')->on('plan_versions')->restrictOnDelete();
            $table->foreign('coupon_id')->references('id')->on('coupons')->restrictOnDelete();
            $table->index('tenant_id');
        });
        DB::statement('CREATE UNIQUE INDEX subscriptions_one_live_per_tenant ON subscriptions (tenant_id) WHERE status <> \'cancelled\'');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('trialing','active','past_due','paused','cancelled'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_collection_check CHECK (collection IN ('stripe','offline'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_interval_check CHECK (interval IN ('month','year'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_amounts_check CHECK (quantity >= 1 AND (custom_amount IS NULL OR custom_amount >= 0))');

        Schema::create('subscription_addons', function (Blueprint $table) {
            $table->uuid('subscription_id');
            $table->uuid('addon_id');
            $table->integer('quantity')->default(1);
            $table->primary(['subscription_id', 'addon_id']);
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->foreign('addon_id')->references('id')->on('plan_addons')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE subscription_addons ADD CONSTRAINT subscription_addons_quantity_check CHECK (quantity >= 1)');

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subscription_id');
            $table->uuid('tenant_id');
            $table->string('kind', 16);
            $table->char('currency', 3);
            $table->bigInteger('mrr_delta');
            $table->timestampTz('occurred_at');
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->restrictOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['currency', 'occurred_at']);
        });
        DB::statement("ALTER TABLE subscription_events ADD CONSTRAINT subscription_events_kind_check CHECK (kind IN ('new','expansion','contraction','churn','reactivation'))");

        Schema::create('stripe_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plan_version_id');
            $table->char('currency', 3);
            $table->string('interval', 8);
            $table->bigInteger('amount');
            $table->string('stripe_price_id', 64)->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['plan_version_id', 'currency', 'interval']);
            $table->foreign('plan_version_id')->references('id')->on('plan_versions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE stripe_prices ADD CONSTRAINT stripe_prices_interval_check CHECK (interval IN ('month','year'))");

        // Default privileges hand the app and report roles DML/SELECT on every new table; billing is not theirs (T7).
        $tables = implode(', ', self::TABLES);
        DB::statement("REVOKE ALL ON {$tables} FROM PUBLIC, ledgerly_app, ledgerly_report");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$tables} TO ledgerly_platform");
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
