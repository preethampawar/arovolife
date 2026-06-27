<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * KP's 2026-06-26 amendment: the 3% admin charge now applies to Growth
     * Booster Bonus and Fortune Bonus too (previously GSB/MB/Rank only).
     * Add the column so the net = gross − admin_charge − tds breakdown is
     * recorded per result row, consistent with gsb_cutoff_results.
     */
    public function up(): void
    {
        Schema::table('gbb_monthly_results', function (Blueprint $table) {
            $table->bigInteger('admin_charge_paise')->default(0)->after('gbb_gross_paise');
        });

        Schema::table('fortune_bonus_results', function (Blueprint $table) {
            $table->bigInteger('admin_charge_paise')->default(0)->after('gross_paise');
        });
    }

    public function down(): void
    {
        Schema::table('gbb_monthly_results', function (Blueprint $table) {
            $table->dropColumn('admin_charge_paise');
        });

        Schema::table('fortune_bonus_results', function (Blueprint $table) {
            $table->dropColumn('admin_charge_paise');
        });
    }
};
