<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots the two figures that now decide a Fortune Bonus payout
 * (KP 2026-08-07): the distributor's FB points for the month and the month's
 * frozen rupee value of one point.
 *
 * Keeping them on the result row is what makes a paid month re-readable years
 * later without re-deriving the matrix — the same reason
 * mentorship_bonus_results keeps msb_point_value_paise.
 *
 * Both are nullable: rows written by the old fixed per-level engine have
 * neither, and must stay distinguishable from a genuine zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fortune_bonus_results', function (Blueprint $table) {
            $table->unsignedInteger('points')->nullable()->after('matrix_level');
            $table->unsignedBigInteger('point_value_paise')->nullable()->after('points');
        });
    }

    public function down(): void
    {
        Schema::table('fortune_bonus_results', function (Blueprint $table) {
            $table->dropColumn(['points', 'point_value_paise']);
        });
    }
};
