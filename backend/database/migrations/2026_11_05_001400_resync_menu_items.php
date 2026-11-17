<?php

declare(strict_types=1);

use App\Support\Menu\MenuItemSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loads database/seeders/menu_items.php again after fund transfers, write-offs and bad-debt provisioning joined the menu (plan 3c.12a,
 * merged after the menu table of 3c.24). `sync()` upserts by key, so a database that already ran 000900 and 000904 gains the new rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        MenuItemSeeder::sync(DB::connection());
    }

    public function down(): void
    {
        // The rows stay; the list is reference data.
    }
};
