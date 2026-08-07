<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the Fortune Bonus per-level FB points (KP 2026-08-07 pool + points
 * engine).
 *
 * A participant earns points from the enrolled distributors BELOW them in the
 * month's 3×9 matrix: one member at relative depth 1, 2 or 3 is worth 9 points,
 * then 8 / 7 / 6 / 5 / 4 and 3 at depth 9. Depth 0 is yourself and is worth
 * nothing. The month's pool (5% of company BV) ÷ everyone's points gives the
 * rupee value of one point.
 *
 * This replaces the former fixed rupee payout per matrix level (bonus_paise,
 * dropped 2026_08_07_100002). Idempotent: upsert keyed on `level`.
 */
final class FortuneBonusLevelsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        $pointsByLevel = [
            0 => 0,
            1 => 9,
            2 => 9,
            3 => 9,
            4 => 8,
            5 => 7,
            6 => 6,
            7 => 5,
            8 => 4,
            9 => 3,
        ];

        $records = [];
        foreach ($pointsByLevel as $level => $points) {
            $records[] = [
                'level' => $level,
                'points_per_member' => $points,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('fortune_bonus_levels')->upsert(
            $records,
            ['level'],
            ['points_per_member', 'is_active', 'updated_at'],
        );
    }
}
