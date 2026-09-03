<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the Fortune Bonus per-level configuration (client notes 2026-09-03).
 *
 * The table does double duty:
 *  - `points_per_member` on row d is read by RELATIVE DEPTH: a participant
 *    earns points from the enrolled distributors BELOW them — one member at
 *    depth 1 is worth 9 points, depth 2 → 8, … depth 9 → 1 (1L-9P … 9L-1P).
 *    Depth 0 is yourself and is worth nothing.
 *  - `payout_mode` / `cap_paise` on row L are read by ABSOLUTE MATRIX LEVEL:
 *    every level is 'capped' — each level recomputes its own whole-rupee
 *    point value from the pool and points carried forward from the level
 *    above, and each member's income is limited to the level's ceiling
 *    ₹30k/₹30k/₹30k/₹30k/₹20k/₹10k/₹5k/₹2,500/₹1,500/₹30. The cap INCLUDES
 *    the ₹30 minimum commission, so level 9's ₹30 cap pays the minimum only.
 *
 * The 2026-09-03 notes supersede the 2026-08-09 cascade, which priced levels
 * 7–8 together at one uncapped 'residual' value and paid level 9 as
 * 'flat_min'; those modes remain understood by the engine for months frozen
 * before the change. Idempotent: upsert keyed on `level`.
 */
final class FortuneBonusLevelsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        // level => [points_per_member (by relative depth), payout_mode, cap_paise]
        $levels = [
            0 => [0, 'capped', 3_000_000],
            1 => [9, 'capped', 3_000_000],
            2 => [8, 'capped', 3_000_000],
            3 => [7, 'capped', 3_000_000],
            4 => [6, 'capped', 2_000_000],
            5 => [5, 'capped', 1_000_000],
            6 => [4, 'capped', 500_000],
            7 => [3, 'capped', 250_000],
            8 => [2, 'capped', 150_000],
            9 => [1, 'capped', 3_000],
        ];

        $records = [];
        foreach ($levels as $level => [$points, $mode, $capPaise]) {
            $records[] = [
                'level' => $level,
                'points_per_member' => $points,
                'payout_mode' => $mode,
                'cap_paise' => $capPaise,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('fortune_bonus_levels')->upsert(
            $records,
            ['level'],
            ['points_per_member', 'payout_mode', 'cap_paise', 'is_active', 'updated_at'],
        );
    }
}
