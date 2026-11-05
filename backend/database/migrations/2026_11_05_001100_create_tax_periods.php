<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax periods (13 §23 D15 item 13, plan 3c.21a): a date range per company and tax authority, monthly by default. open → filed, with
 * who, when, the authority's reference and a frozen snapshot of the return. Filing never touches a ledger period. Periods of one
 * authority never overlap (exclusion constraint). A filed period carries its filing; reopening clears it (the audit trail keeps
 * the old snapshot) and records who reopened it and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            $table->string('authority', 60);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('due_on')->nullable();
            $table->enum('status', ['open', 'filed'])->default('open');
            $table->timestampTz('filed_at')->nullable();
            $table->uuid('filed_by')->nullable();
            $table->string('authority_reference', 120)->nullable();
            $table->jsonb('return_snapshot')->nullable();
            $table->jsonb('manual_amounts')->default('{}');
            $table->timestampTz('reopened_at')->nullable();
            $table->uuid('reopened_by')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'company_id', 'id']);
            $table->index(['company_id', 'authority', 'starts_on']);
        });
        DB::statement('ALTER TABLE tax_periods ADD CONSTRAINT tax_periods_range CHECK (ends_on >= starts_on)');
        DB::statement("ALTER TABLE tax_periods ADD CONSTRAINT tax_periods_filed_has_filing CHECK (status = 'open' OR (filed_at IS NOT NULL AND filed_by IS NOT NULL AND authority_reference IS NOT NULL AND return_snapshot IS NOT NULL))");
        DB::statement("ALTER TABLE tax_periods ADD CONSTRAINT tax_periods_no_overlap EXCLUDE USING gist (company_id WITH =, authority WITH =, daterange(starts_on, ends_on, '[]') WITH &&)");
        Rls::enable(DB::connection(), 'tax_periods');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_periods');
    }
};
