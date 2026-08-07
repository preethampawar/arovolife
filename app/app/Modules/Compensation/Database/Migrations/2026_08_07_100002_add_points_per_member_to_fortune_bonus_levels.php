<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Swaps the Fortune Bonus per-level rupee payout for per-level FB points
 * (KP 2026-08-07).
 *
 * A Fortune participant is no longer paid a fixed amount for the matrix level
 * they happen to occupy. They now earn POINTS from the enrolled distributors
 * below them: a member at relative depth 1–3 is worth 9 points, then 8/7/6/5/4
 * and 3 at depth 9; deeper than 9 is worth nothing. The month's pool
 * (5% of company BV) ÷ everyone's points gives the rupee value of one point.
 *
 * bonus_paise therefore has no readers left and is dropped in the same
 * migration — no old-requirement setting may survive (user directive
 * 2026-08-07). The Fortune feature flag has never been ON outside tests, so
 * there is no historical payout data that depended on the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL does not roll DDL back, so a run that died between the two
        // statements below must be resumable.
        if (! Schema::hasColumn('fortune_bonus_levels', 'points_per_member')) {
            Schema::table('fortune_bonus_levels', function (Blueprint $table) {
                $table->unsignedInteger('points_per_member')->default(0)->after('level');
            });
        }

        if (Schema::hasColumn('fortune_bonus_levels', 'bonus_paise')) {
            Schema::table('fortune_bonus_levels', function (Blueprint $table) {
                $table->dropColumn('bonus_paise');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fortune_bonus_levels', 'bonus_paise')) {
            Schema::table('fortune_bonus_levels', function (Blueprint $table) {
                $table->unsignedBigInteger('bonus_paise')->default(0)->after('level');
            });
        }

        if (Schema::hasColumn('fortune_bonus_levels', 'points_per_member')) {
            Schema::table('fortune_bonus_levels', function (Blueprint $table) {
                $table->dropColumn('points_per_member');
            });
        }
    }
};
