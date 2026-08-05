<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots the frozen per-AGP point value each GBB result row was priced at.
 *
 * Nullable on purpose: legacy rows were written before the monthly pool was
 * frozen (their value was derived on the fly from pool_paise ÷ total_pool_agp)
 * and must stay distinguishable from rows priced against a gbb_monthly_pools
 * snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gbb_monthly_results', function (Blueprint $table): void {
            $table->unsignedBigInteger('point_value_paise')->nullable()->after('total_pool_agp');
        });
    }

    public function down(): void
    {
        Schema::table('gbb_monthly_results', function (Blueprint $table): void {
            $table->dropColumn('point_value_paise');
        });
    }
};
