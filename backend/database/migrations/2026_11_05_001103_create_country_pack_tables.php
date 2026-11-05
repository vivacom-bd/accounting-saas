<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Country packs applied to a company (plan 3c.22). The pack itself is data in `resources/country-packs`; these tables hold what a
 * company took from it and may edit: the settings, the return sections and the mapping of tax codes to them. Applying a pack only
 * adds rows; nothing here is ever overwritten by a later apply.
 *
 * `company_tax_packs`: one pack per company. `tax_return_sections`: a labelled line of the return; `source_kind` says where its
 * figure comes from (mapped tax codes, the reverse-charge output or input, or an amount entered on the tax period).
 * `tax_return_section_codes`: which tax codes, on which side, feed a section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_tax_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            $table->string('pack_key', 40);
            $table->integer('pack_version');
            $table->string('authority', 60);
            $table->smallInteger('due_days_after_period')->nullable();
            $table->enum('reverse_charge_mode', ['gross', 'net_non_creditable'])->default('gross');
            $table->uuid('applied_by')->nullable();
            $table->timestampTz('applied_at');
            $table->timestampTz('last_applied_at');
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->unique(['company_id']);
            $table->unique(['tenant_id', 'company_id', 'id']);
        });
        DB::statement('ALTER TABLE company_tax_packs ADD CONSTRAINT company_tax_packs_due_days CHECK (due_days_after_period IS NULL OR due_days_after_period BETWEEN 0 AND 120)');
        Rls::enable(DB::connection(), 'company_tax_packs');

        Schema::create('tax_return_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            $table->string('section_key', 60);
            $table->string('pack_key', 40);
            $table->enum('group_key', ['output', 'input', 'adjustments', 'credits']);
            $table->string('label', 255);
            $table->text('note')->nullable();
            $table->smallInteger('sort');
            $table->enum('effect', ['add', 'subtract', 'none']);
            $table->enum('source_kind', ['codes', 'reverse_charge_output', 'reverse_charge_input', 'manual']);
            $table->timestampsTz();
            $table->unique(['company_id', 'section_key']);
            $table->unique(['tenant_id', 'company_id', 'id']);
        });
        Rls::enable(DB::connection(), 'tax_return_sections');

        Schema::create('tax_return_section_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            Rls::companyRef($table, 'section_id', 'tax_return_sections');
            Rls::companyRef($table, 'tax_code_id', 'tax_codes');
            $table->enum('side', ['sales', 'purchases', 'reverse_charge']);
            $table->timestampsTz();
            $table->unique(['section_id', 'tax_code_id', 'side']);
        });
        Rls::enable(DB::connection(), 'tax_return_section_codes');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_return_section_codes');
        Schema::dropIfExists('tax_return_sections');
        Schema::dropIfExists('company_tax_packs');
    }
};
