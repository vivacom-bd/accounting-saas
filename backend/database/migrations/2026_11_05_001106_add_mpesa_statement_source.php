<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** M-Pesa statements can be imported (plan 3c.29): the `bank_statements.source` check admits `mpesa`. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bank_statements DROP CONSTRAINT IF EXISTS bank_statements_source_check');
        DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_source_check CHECK (source::text IN ('csv', 'ofx', 'camt', 'manual', 'mpesa'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_statements DROP CONSTRAINT IF EXISTS bank_statements_source_check');
        DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_source_check CHECK (source::text IN ('csv', 'ofx', 'camt', 'manual'))");
    }
};
