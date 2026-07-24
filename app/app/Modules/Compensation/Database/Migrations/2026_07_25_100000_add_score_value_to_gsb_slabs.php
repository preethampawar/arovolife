<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-slab configurable GSB score value (KP 2026-07-21). The GSB bonus becomes
 * score × score_value_paise, replacing the single global comp.gsb.score_rate_paise
 * rate so each of the 7 slabs can carry its own rupee-per-score value from admin.
 * Default ₹250 = 25,000 paise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gsb_slabs', function (Blueprint $table) {
            $table->unsignedBigInteger('score_value_paise')->default(25_000)->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('gsb_slabs', function (Blueprint $table) {
            $table->dropColumn('score_value_paise');
        });
    }
};
