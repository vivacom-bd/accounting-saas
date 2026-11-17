<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Findings of the nightly ledger drift detector (13 §9, plan 4.2). One row per distinct mismatch: `fingerprint` is a hash of
 * what was found, so a rerun over the same state records nothing new, while a mismatch that changes is a new finding.
 *
 * `balance`: expected is the sum of posted journal lines for the account, period and dimension values; stored is the
 * `account_period_balances` row (zero when it is missing). `journal`: expected is the sum of the journal's lines, stored is the
 * total on its header. `trial_balance`: stored is the projection's total debit and credit for the company, and both expected
 * figures are the total credit, because debits and credits must be equal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_drift_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            $table->string('kind', 16);
            $table->uuid('account_id')->nullable();
            $table->uuid('fiscal_period_id')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->string('dimensions', 400)->nullable();
            $table->bigInteger('expected_debit');
            $table->bigInteger('expected_credit');
            $table->bigInteger('stored_debit');
            $table->bigInteger('stored_credit');
            $table->char('fingerprint', 64);
            $table->timestampTz('detected_at');
            $table->unique(['tenant_id', 'fingerprint']);
            $table->index(['company_id', 'detected_at']);
        });
        DB::statement("ALTER TABLE ledger_drift_findings ADD CONSTRAINT ledger_drift_findings_kind_check CHECK (kind IN ('balance', 'journal', 'trial_balance'))");
        Rls::enable(DB::connection(), 'ledger_drift_findings');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_drift_findings');
    }
};
