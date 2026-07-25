<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Points-engine snapshots for the Mentorship Bonus (KP 2026-07-25 sheet):
 * the sponsee's matched GSB slab plus the points and per-point value captured
 * at credit time, so historical rows survive later admin edits. The old
 * ladder columns (mb_rate_pct, sponsee_cumulative_gsb_paise) become nullable —
 * legacy rows keep their values; the new engine writes null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mentorship_bonus_results', function (Blueprint $table) {
            $table->unsignedTinyInteger('slab')->nullable()->after('sponsee_gsb_paise');
            $table->unsignedInteger('msb_points')->nullable()->after('slab');
            $table->unsignedBigInteger('msb_point_value_paise')->nullable()->after('msb_points');
            $table->unsignedTinyInteger('mb_rate_pct')->nullable()->change();
            $table->bigInteger('sponsee_cumulative_gsb_paise')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mentorship_bonus_results', function (Blueprint $table) {
            $table->dropColumn(['slab', 'msb_points', 'msb_point_value_paise']);
        });
    }
};
