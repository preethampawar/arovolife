<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reorder point per (variant, warehouse), and the stamp that keeps the
 * daily alert from mailing the same shortage every morning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_levels', function (Blueprint $table): void {
            $table->integer('reorder_level')->default(0)->after('reserved');
            $table->dateTime('low_stock_alerted_at', 3)->nullable()->after('reorder_level');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_levels', function (Blueprint $table): void {
            $table->dropColumn(['reorder_level', 'low_stock_alerted_at']);
        });
    }
};
