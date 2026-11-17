<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GDPR request tracking (10_SaaS_Admin §3.12, plan Task 18): a queue with a 30-day due date. Fulfilment is manual until
 * plan step 4.7. A platform table (Rls::GLOBAL_TABLES), `ledgerly_platform` only. The subject's email is kept as a
 * sha256 of the lowercased address plus a masked form, never in the clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gdpr_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('kind', 16);
            $table->char('subject_email_hash', 64);
            $table->string('subject_email_masked', 255);
            $table->string('status', 16)->default('open');
            $table->uuid('opened_by');
            $table->timestampTz('due_at');
            $table->timestampTz('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('opened_by')->references('id')->on('admin_users')->restrictOnDelete();
            $table->index(['status', 'due_at']);
            $table->index('tenant_id');
        });
        DB::statement("ALTER TABLE gdpr_requests ADD CONSTRAINT gdpr_requests_kind_check CHECK (kind IN ('export','erasure','rectification','access'))");
        DB::statement("ALTER TABLE gdpr_requests ADD CONSTRAINT gdpr_requests_status_check CHECK (status IN ('open','in_progress','done','rejected'))");

        DB::statement('REVOKE ALL ON gdpr_requests FROM PUBLIC, ledgerly_app, ledgerly_report');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON gdpr_requests TO ledgerly_platform');
    }

    public function down(): void
    {
        Schema::dropIfExists('gdpr_requests');
    }
};
