<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the Fortune Bonus per-level configuration (KP 2026-08-09 cascade).
 *
 * The table does double duty:
 *  - `points_per_member` on row d is read by RELATIVE DEPTH: a participant
 *    earns points from the enrolled distributors BELOW them — one member at
 *    depth 1 is worth 9 points, depth 2 → 8, … depth 9 → 1 (1L-9P … 9L-1P).
 *    Depth 0 is yourself and is worth nothing.
 *  - `payout_mode` / `cap_paise` on row L are read by ABSOLUTE MATRIX LEVEL:
 *    levels 0–6 are 'capped' with per-member ceilings ₹30k/₹30k/₹30k/₹30k/
 *    ₹20k/₹10k/₹5k (the cap INCLUDES the ₹30 minimum commission), levels 7–8
 *    are 'residual' (one shared point value over their combined points, no
 *    cap) and level 9 is 'flat_min' (the ₹30 minimum only).
 *
 * This supersedes the 2026-08-07 single-point-value engine (depth points
 * 9/9/9/8/7/6/5/4/3, one global value). Idempotent: upsert keyed on `level`.
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
            7 => [3, 'residual', null],
            8 => [2, 'residual', null],
            9 => [1, 'flat_min', null],
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
