<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 2026-08-07 migration added `points_per_member` with default 0, leaving
 * every existing row at 0. The 2026-09-03 migration only updated payout_mode
 * and cap_paise. On environments where FortuneBonusLevelsSeeder was not re-run
 * (e.g. staging), points_per_member is still 0 for all levels.
 *
 * Sets the correct depth-point values unconditionally:
 *   Level 0 (self) → 0 pts, Levels 1–9 → 9, 8, 7, 6, 5, 4, 3, 2, 1 pts.
 */
return new class extends Migration
{
    /** @var array<int, int> level => points_per_member */
    private const array POINTS = [
        0 => 0,
        1 => 9,
        2 => 8,
        3 => 7,
        4 => 6,
        5 => 5,
        6 => 4,
        7 => 3,
        8 => 2,
        9 => 1,
    ];

    public function up(): void
    {
        foreach (self::POINTS as $level => $points) {
            DB::table('fortune_bonus_levels')
                ->where('level', $level)
                ->update(['points_per_member' => $points, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('fortune_bonus_levels')
            ->update(['points_per_member' => 0, 'updated_at' => now()]);
    }
};
