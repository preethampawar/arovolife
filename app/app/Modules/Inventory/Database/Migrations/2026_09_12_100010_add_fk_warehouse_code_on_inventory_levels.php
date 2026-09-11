<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point `inventory_levels.warehouse_code` at the new registry. Safe without a
 * backfill: every existing row carries 'DEFAULT', which 100000 seeded.
 *
 * On SQLite this is a no-op — the driver cannot add a constraint to an existing
 * table and Laravel's SQLite grammar compiles the command to nothing, so the
 * test suite runs without it. It applies on MySQL, which is where it matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('inventory_levels', function (Blueprint $table): void {
            $table->foreign('warehouse_code', 'fk_inventory_levels_warehouse_code')
                ->references('code')->on('warehouses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('inventory_levels', function (Blueprint $table): void {
            $table->dropForeign('fk_inventory_levels_warehouse_code');
        });
    }
};
