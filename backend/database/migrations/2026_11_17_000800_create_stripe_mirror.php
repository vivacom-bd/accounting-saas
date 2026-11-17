<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe mirror (10_SaaS_Admin §6; plan Task 12): the raw webhook events, the invoice and payout mirrors, and the
 * dunning notices already sent. Platform tables (no RLS, allowlisted in Rls::GLOBAL_TABLES); only `ledgerly_platform`
 * touches them. Money is integer minor units.
 *
 * `updated_from_event_at` on invoices and payouts is the `created` time of the newest event that wrote the row, so an
 * older delivery never overwrites a newer one (Review Focus 3). `stripe_invoices.source` ('stripe'|'offline') is
 * for Task 13's manually invoiced subscriptions; offline rows have no Stripe id.
 * `stripe_events.attention` marks an event staff must look at (a payment that arrived for a cancelled tenant).
 */
return new class extends Migration
{
    private const array TABLES = ['stripe_events', 'stripe_invoices', 'stripe_payouts', 'billing_notices_sent'];

    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('stripe_event_id', 64)->unique();
            $table->string('type', 64);
            $table->jsonb('payload');
            $table->timestampTz('created_at_stripe');
            $table->timestampTz('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->text('attention')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('processed_at');
        });

        Schema::create('stripe_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('stripe_id', 64)->nullable()->unique();
            $table->string('source', 8)->default('stripe');
            $table->string('number', 64)->nullable();
            $table->string('status', 16);
            $table->char('currency', 3);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->bigInteger('amount_refunded')->default(0);
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->jsonb('lines')->default('[]');
            $table->text('pdf_url')->nullable();
            $table->unsignedSmallInteger('dunning_stage')->default(0);
            $table->timestampTz('updated_from_event_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("ALTER TABLE stripe_invoices ADD CONSTRAINT stripe_invoices_source_check CHECK (source IN ('stripe','offline'))");
        DB::statement("ALTER TABLE stripe_invoices ADD CONSTRAINT stripe_invoices_status_check CHECK (status IN ('draft','open','paid','void','uncollectible'))");
        DB::statement('ALTER TABLE stripe_invoices ADD CONSTRAINT stripe_invoices_stripe_id_check CHECK (source = \'offline\' OR stripe_id IS NOT NULL)');
        DB::statement('ALTER TABLE stripe_invoices ADD CONSTRAINT stripe_invoices_amounts_check CHECK (amount_refunded >= 0 AND amount_paid >= 0)');

        Schema::create('stripe_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('stripe_id', 64)->unique();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->date('arrival_date')->nullable();
            $table->string('status', 16);
            $table->timestampTz('updated_from_event_at')->nullable();
            $table->timestampsTz();
        });

        // One notice per tenant, kind, offset day and anchor (the delinquency or trial-end date), so a tenant that
        // falls behind again later is reminded again.
        Schema::create('billing_notices_sent', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('kind', 24);
            $table->smallInteger('day');
            $table->date('anchor');
            $table->timestampTz('sent_at')->useCurrent();
            $table->primary(['tenant_id', 'kind', 'day', 'anchor']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });

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
