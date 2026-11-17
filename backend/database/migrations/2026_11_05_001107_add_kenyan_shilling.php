<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Kenyan shilling (plan 3c.22). The currency list had no KES, so a Kenyan company could not even be created ("Unknown currency
 * KES"). ISO 4217: two minor digits. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("INSERT INTO currencies (code, name, minor_unit, symbol) VALUES ('KES', 'Kenyan Shilling', 2, 'KSh') ON CONFLICT (code) DO NOTHING");
    }

    public function down(): void
    {
        // Companies may already use it; the row stays.
    }
};
