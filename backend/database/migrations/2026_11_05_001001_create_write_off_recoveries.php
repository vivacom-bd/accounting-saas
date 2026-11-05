<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery after write-off (13 §23 D15 items 2 and 9, plan 3c.12a): its own approved document against one posted write-off, for up
 * to the amount written off and not yet recovered. Posting reinstates the receivable (Dr receivable control with the contact, Cr
 * bad-debt recovery), lowers the invoice's `settled_fc`/`settled` so it reopens by that amount, and raises `write_offs.recovered_fc`.
 * The money then arrives through an ordinary receipt. `amount_fc` is in the invoice's currency; `amount` is the base carried at the
 * write-off's rate (the recovery that completes the write-off takes the remaining base).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('write_off_recoveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            $table->string('number', 32)->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'posted', 'reversed', 'void'])->default('draft');
            Rls::companyRef($table, 'write_off_id', 'write_offs');
            Rls::companyRef($table, 'invoice_id', 'invoices');
            Rls::companyRef($table, 'contact_id', 'contacts');
            $table->date('recovery_date');
            $table->char('currency', 3);
            $table->decimal('fx_rate', 20, 8)->default(1);
            $table->bigInteger('amount_fc');
            $table->bigInteger('amount');
            $table->text('reason');
            $table->string('reference')->nullable();
            $table->text('memo')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->uuid('reversal_journal_id')->nullable();
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'company_id', 'id']);
            $table->unique(['tenant_id', 'company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'write_off_id']);
            $table->foreign('currency')->references('code')->on('currencies');
        });
        foreach ([
            'write_off_recoveries_maker_checker' => 'approved_by IS NULL OR approved_by IS DISTINCT FROM created_by',
            'write_off_recoveries_posted_complete' => "status NOT IN ('posted', 'reversed') OR (number IS NOT NULL AND posted_at IS NOT NULL AND journal_id IS NOT NULL)",
            'write_off_recoveries_amounts_check' => 'amount_fc > 0 AND amount > 0 AND fx_rate > 0',
            'write_off_recoveries_reason_check' => 'length(btrim(reason)) > 0',
        ] as $name => $expression) {
            DB::statement("ALTER TABLE write_off_recoveries ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
        Rls::enable(DB::connection(), 'write_off_recoveries');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER write_off_recoveries_posted_row_guard
              BEFORE UPDATE OR DELETE ON write_off_recoveries
              FOR EACH ROW EXECUTE FUNCTION posted_row_guard('reversed', 'reversal_journal_id', 'lock_version', 'updated_at');
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('write_off_recoveries');
    }
};
