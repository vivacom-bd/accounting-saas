<?php

declare(strict_types=1);

use App\Support\Database\SqlFile;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The plan catalogue (10_SaaS_Admin §3.3, §3.4, §6; plan Task 9): plans, immutable versions, add-ons and coupons.
 * Platform tables (no tenant_id, no RLS; allowlisted in Rls::GLOBAL_TABLES). Only `ledgerly_platform` touches them
 * directly; the tenant app reads entitlements through sysops functions (Task 10). The four starting plans and the
 * add-ons are reference data, loaded here and re-runnable through PlanSeeder::sync().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX plans_code_unique ON plans (lower(code))');

        Schema::create('plan_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plan_id');
            $table->integer('version');
            $table->jsonb('prices');
            $table->jsonb('limits');
            $table->jsonb('entitlements');
            $table->integer('trial_days')->default(14);
            $table->string('stripe_product_id', 64)->nullable();
            $table->timestampTz('effective_from');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['plan_id', 'version']);
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('admin_users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_version_check CHECK (version >= 1 AND trial_days >= 0)');
        DB::statement('CREATE INDEX plan_versions_effective ON plan_versions (plan_id, effective_from)');

        Schema::create('plan_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 48);
            $table->string('name', 160);
            $table->string('kind', 16);
            $table->jsonb('price');
            $table->string('unit_label', 80)->nullable();
            $table->string('stripe_price_id', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX plan_addons_code_unique ON plan_addons (lower(code))');
        DB::statement("ALTER TABLE plan_addons ADD CONSTRAINT plan_addons_kind_check CHECK (kind IN ('company','user_pack','storage','metered_api'))");

        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 48);
            $table->string('stripe_coupon_id', 64)->nullable();
            $table->smallInteger('percent')->nullable();
            $table->bigInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('duration', 16);
            $table->smallInteger('duration_months')->nullable();
            $table->jsonb('restrictions')->default('{}');
            $table->integer('max_redemptions')->nullable();
            $table->integer('redeemed_count')->default(0);
            $table->timestampTz('expires_at')->nullable();
            $table->uuid('partner_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX coupons_code_unique ON coupons (lower(code))');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_discount_check CHECK (
            (percent IS NOT NULL AND amount IS NULL AND currency IS NULL AND percent BETWEEN 1 AND 100)
            OR (percent IS NULL AND amount IS NOT NULL AND currency IS NOT NULL AND amount > 0))');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_duration_check CHECK (
            duration IN ('once','repeating','forever')
            AND ((duration = 'repeating') = (duration_months IS NOT NULL))
            AND (duration_months IS NULL OR duration_months >= 1))");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_redemptions_check CHECK (max_redemptions IS NULL OR max_redemptions >= 1)');

        // Default privileges hand the app and report roles DML/SELECT on every new table; the catalogue is not theirs.
        $db = DB::connection();
        $tables = 'plans, plan_versions, plan_addons, coupons';
        $db->statement("REVOKE ALL ON {$tables} FROM PUBLIC, ledgerly_app, ledgerly_report");
        $db->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$tables} TO ledgerly_platform");

        SqlFile::run($db, 'plan_versions_guard.sql');

        PlanSeeder::sync($db);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS plan_versions_guard ON plan_versions');
        DB::statement('DROP FUNCTION IF EXISTS plan_versions_guard()');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('plan_addons');
        Schema::dropIfExists('plan_versions');
        Schema::dropIfExists('plans');
    }
};
