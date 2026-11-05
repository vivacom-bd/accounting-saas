<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use App\Support\Database\SqlFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-invoicing (plan 3c.27). `company_einvoicing`: off for every company until someone turns it on, which needs a chosen adapter and the
 * seller's tax id (CHECK). `einvoice_submissions`: one row per posted document version that has to be sent. The authority's number is
 * kept in its own column, apart from the document's gapless number. Rows are created from the outbox AFTER posting, never in the posting
 * transaction. A document with no row is `not_required`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_einvoicing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            $table->boolean('enabled')->default(false);
            $table->string('adapter_key', 40)->nullable();
            $table->string('seller_tax_id', 40)->nullable();
            $table->string('seller_branch', 40)->nullable();
            $table->uuid('changed_by')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->unique(['company_id']);
            $table->unique(['tenant_id', 'company_id', 'id']);
        });
        DB::statement('ALTER TABLE company_einvoicing ADD CONSTRAINT company_einvoicing_needs_adapter CHECK (NOT enabled OR (adapter_key IS NOT NULL AND seller_tax_id IS NOT NULL))');
        Rls::enable(DB::connection(), 'company_einvoicing');

        Schema::create('einvoice_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            Rls::companyRef($table, 'invoice_id', 'invoices');
            $table->integer('document_version');
            $table->string('idempotency_key', 80);
            $table->enum('document_type', ['invoice', 'credit_note', 'debit_note']);
            $table->string('adapter_key', 40);
            $table->enum('status', ['pending', 'submitting', 'accepted', 'rejected', 'failed_retryable', 'unknown', 'voided_by_credit'])->default('pending');
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('dead_lettered_at')->nullable();
            $table->string('last_error_code', 60)->nullable();
            $table->text('last_error_message')->nullable();
            $table->string('authority_number', 120)->nullable();
            $table->text('authority_signature')->nullable();
            $table->text('qr_payload')->nullable();
            $table->timestampTz('authority_timestamp')->nullable();
            $table->string('device_id', 120)->nullable();
            $table->jsonb('raw_request')->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'company_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'company_id', 'invoice_id', 'document_version']);
            $table->unique(['tenant_id', 'company_id', 'id']);
            $table->index(['status', 'next_attempt_at']);
        });
        DB::statement("ALTER TABLE einvoice_submissions ADD CONSTRAINT einvoice_submissions_accepted_has_number CHECK (status NOT IN ('accepted', 'voided_by_credit') OR authority_number IS NOT NULL)");
        Rls::enable(DB::connection(), 'einvoice_submissions');

        $db = DB::connection();
        $db->statement('SET LOCAL ROLE ledgerly_sysops');
        SqlFile::run($db, 'sysops_einvoicing.sql');
        $db->statement('RESET ROLE');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS sysops.einvoice_submissions_due(timestamptz, integer)');
        Schema::dropIfExists('einvoice_submissions');
        Schema::dropIfExists('company_einvoicing');
    }
};
